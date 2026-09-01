<?php

declare(strict_types=1);

namespace Drupal\unisonges_editorial_home;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\views\ResultRow;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the anonymous editorial stream used on the homepage.
 */
final class EditorialHomeBuilder {

  private const VIEW_ID = 'blog_posts';

  private const DISPLAY_ID = 'editorial_home';

  private const ARTICLE_BUNDLE = 'article';

  private const TAG_VOCABULARY = 'tags';

  private const TAG_FIELD = 'field_tags';

  private const SUMMARY_FIELD = 'body';

  private const ITEMS_PER_PAGE = 10;

  private const MAX_PAGE = 10000;

  /**
   * Constructs an EditorialHomeBuilder object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly ViewExecutableFactory $viewExecutableFactory,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly RequestStack $requestStack,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Builds the editorial homepage render array.
   *
   * @return array<string, mixed>
   *   A render array for the editorial homepage theme hook.
   */
  public function build(): array {
    $request = $this->requestStack->getCurrentRequest();
    $query = $request?->query->all() ?? [];
    $theme_input = $this->parseThemeInput($query);
    $page = $this->parsePageInput($query);

    $cacheability = (new CacheableMetadata())
      ->setCacheContexts([
        'languages:' . LanguageInterface::TYPE_CONTENT,
        'languages:' . LanguageInterface::TYPE_INTERFACE,
        'languages:' . LanguageInterface::TYPE_URL,
        'timezone',
        'url.path',
        'url.query_args:page',
        'url.query_args:theme',
        'user.node_grants:view',
        'user.permissions',
      ]);
    $this->addListCacheability($cacheability);

    $articles = [];
    $themes = [];
    $selected_term = NULL;
    $theme_invalid = $theme_input['invalid'];
    $has_next = FALSE;

    $view_storage = $this->entityTypeManager
      ->getStorage('view')
      ->load(self::VIEW_ID);
    if ($view_storage instanceof ViewEntityInterface) {
      $cacheability->addCacheableDependency($view_storage);
    }

    $anonymous = new AnonymousUserSession();
    $this->accountSwitcher->switchTo($anonymous);

    try {
      if (!$theme_invalid && $theme_input['tid'] !== NULL) {
        $selected_term = $this->loadEligibleTerm(
          $theme_input['tid'],
          $anonymous,
          $cacheability,
        );
        $theme_invalid = !($selected_term instanceof TermInterface);
      }

      if ($view_storage instanceof ViewEntityInterface) {
        $choices_view = $this->executeView(
          $view_storage,
          'all',
          0,
          TRUE,
          $anonymous,
          $cacheability,
        );
        if ($choices_view instanceof ViewExecutable) {
          $themes = $this->buildThemeChoices(
            $choices_view,
            $anonymous,
            $cacheability,
          );
        }

        if (!$theme_invalid) {
          $argument = $selected_term instanceof TermInterface
            ? (string) $selected_term->id()
            : 'all';
          $page_view = $this->executeView(
            $view_storage,
            $argument,
            $page,
            FALSE,
            $anonymous,
            $cacheability,
          );

          if ($page_view instanceof ViewExecutable) {
            $articles = $this->buildArticles(
              $page_view,
              $anonymous,
              $cacheability,
            );
            $pager = $page_view->getPager();
            $has_next = $pager->hasMoreRecords();
          }
        }
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $unfiltered_first_page = !$theme_invalid
      && !($selected_term instanceof TermInterface)
      && $page === 0;
    if ($unfiltered_first_page && isset($articles[0])) {
      $articles[0]['emphasized'] = TRUE;
    }

    $selected_theme = $selected_term instanceof TermInterface
      ? (string) $selected_term->id()
      : NULL;
    foreach ($themes as &$theme) {
      $theme['url'] = $this->buildThemeUrl($theme['id']);
      $theme['current'] = $selected_theme === $theme['id'] && !$theme_invalid;
    }
    unset($theme);

    if (!$theme_invalid && $selected_term instanceof TermInterface) {
      $pager_theme = $selected_theme;
    }
    else {
      $pager_theme = NULL;
    }

    $pager = [
      'page' => $page,
      'previous' => !$theme_invalid && $page > 0
        ? $this->buildPageUrl($page - 1, $pager_theme)
        : NULL,
      'next' => $has_next
        ? $this->buildPageUrl($page + 1, $pager_theme)
        : NULL,
    ];

    $build = [
      '#theme' => 'unisonges_editorial_home',
      '#articles' => $articles,
      '#themes' => $themes,
      '#selected_theme' => $selected_theme,
      '#selected_theme_name' => $selected_term?->label(),
      '#theme_filtered' => $selected_term instanceof TermInterface
        && !$theme_invalid,
      '#theme_invalid' => $theme_invalid,
      '#pager' => $pager,
      '#all_articles_url' => Url::fromUserInput('/accueil'),
      '#about_url' => Url::fromUserInput('/a-propos'),
      '#attached' => [
        'library' => [
          'unisonges_editorial_home/editorial_home',
        ],
      ],
    ];

    if ($theme_input['present']) {
      $build['#attached']['html_head'][] = [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'robots',
            'content' => 'noindex,follow',
          ],
        ],
        'unisonges_editorial_home_theme_robots',
      ];
    }

    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Executes the owned Views display with controlled arguments and paging.
   */
  private function executeView(
    ViewEntityInterface $view_storage,
    string $argument,
    int $page,
    bool $unpaged,
    AccountInterface $account,
    CacheableMetadata $cacheability,
  ): ?ViewExecutable {
    $view = $this->viewExecutableFactory->get($view_storage);
    if (!$view->setDisplay(self::DISPLAY_ID)
      || !$view->access(self::DISPLAY_ID, $account)) {
      return NULL;
    }

    $view->setArguments([$argument]);
    $view->setCurrentPage($page);
    $view->setItemsPerPage($unpaged ? 0 : self::ITEMS_PER_PAGE);

    $view->preExecute([$argument]);
    try {
      $executed = $view->execute(self::DISPLAY_ID);
    }
    finally {
      $view->postExecute();
    }

    if (!$executed) {
      return NULL;
    }

    $cacheability->addCacheTags($view->getCacheTags());
    return $view;
  }

  /**
   * Creates filter choices from tags on every eligible unfiltered result.
   *
   * @return array<int, array{id: string, name: string}>
   *   Real accessible tag choices sorted by their translated labels.
   */
  private function buildThemeChoices(
    ViewExecutable $view,
    AccountInterface $account,
    CacheableMetadata $cacheability,
  ): array {
    $terms = [];
    foreach ($this->getEligibleNodes($view, $account, $cacheability) as $node) {
      foreach ($this->getEligibleTerms($node, $account, $cacheability) as $term) {
        $terms[(string) $term->id()] = $term;
      }
    }

    uasort(
      $terms,
      static function (TermInterface $left, TermInterface $right): int {
        $label_order = strnatcasecmp(
          (string) $left->label(),
          (string) $right->label(),
        );
        return $label_order !== 0
          ? $label_order
          : ((int) $left->id() <=> (int) $right->id());
      },
    );

    $choices = [];
    foreach ($terms as $term) {
      $choices[] = [
        'id' => (string) $term->id(),
        'name' => (string) $term->label(),
      ];
    }
    return $choices;
  }

  /**
   * Builds deliberately small article view models from Views results.
   *
   * @return array<int, array<string, mixed>>
   *   Article view models in the View's result order.
   */
  private function buildArticles(
    ViewExecutable $view,
    AccountInterface $account,
    CacheableMetadata $cacheability,
  ): array {
    $articles = [];
    $langcode = $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)
      ->getId();

    foreach ($this->getEligibleNodes($view, $account, $cacheability) as $node) {
      $title_access = $node->get('title')->access('view', $account, TRUE);
      $created_access = $node->get('created')->access('view', $account, TRUE);
      $cacheability
        ->addCacheableDependency($title_access)
        ->addCacheableDependency($created_access);
      if (!$title_access->isAllowed() || !$created_access->isAllowed()) {
        continue;
      }

      $canonical_url = $node->toUrl('canonical');
      $url_access = $canonical_url->access($account, TRUE);
      $cacheability->addCacheableDependency($url_access);
      if (!$url_access->isAllowed()) {
        continue;
      }

      $created = $node->getCreatedTime();
      $terms = [];
      foreach ($this->getEligibleTerms($node, $account, $cacheability) as $term) {
        $terms[] = (string) $term->label();
      }

      $articles[] = [
        'title' => (string) $node->label(),
        'date' => $this->dateFormatter->format(
          $created,
          'custom',
          'j F Y',
          NULL,
          $langcode,
        ),
        'datetime' => $this->dateFormatter->format(
          $created,
          'custom',
          'Y-m-d\\TH:i:sP',
          NULL,
          $langcode,
        ),
        'terms' => $terms,
        'summary' => $this->buildExplicitSummary(
          $node,
          $account,
          $cacheability,
        ),
        'url' => $canonical_url,
        'emphasized' => FALSE,
      ];
    }

    return $articles;
  }

  /**
   * Returns accessible published Article entities from a View result.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Eligible nodes, deduplicated without changing their result order.
   */
  private function getEligibleNodes(
    ViewExecutable $view,
    AccountInterface $account,
    CacheableMetadata $cacheability,
  ): array {
    $nodes = $this->extractResultNodes($view);
    $eligible = [];

    foreach ($nodes as $node) {
      $cacheability->addCacheableDependency($node);
      $translated = $this->entityRepository->getTranslationFromContext($node);
      if ($translated instanceof NodeInterface) {
        $node = $translated;
        $cacheability->addCacheableDependency($node);
      }

      $access = $node->access('view', $account, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($node->bundle() !== self::ARTICLE_BUNDLE
        || !$node->isPublished()
        || !$access->isAllowed()) {
        continue;
      }

      $eligible[(string) $node->id()] = $node;
    }

    return array_values($eligible);
  }

  /**
   * Extracts row entities, loading guarded node IDs only as a fallback.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Node entities in View result order.
   */
  private function extractResultNodes(ViewExecutable $view): array {
    $nodes_by_delta = [];
    $fallback_ids = [];

    foreach ($view->result as $delta => $row) {
      if (!$row instanceof ResultRow) {
        continue;
      }
      if ($row->_entity instanceof NodeInterface) {
        $nodes_by_delta[$delta] = $row->_entity;
        continue;
      }

      $nid = $this->extractResultNodeId($row);
      if ($nid !== NULL) {
        $fallback_ids[$delta] = $nid;
      }
    }

    if ($fallback_ids !== []) {
      $loaded = $this->entityTypeManager
        ->getStorage('node')
        ->loadMultiple(array_values(array_unique($fallback_ids)));
      foreach ($fallback_ids as $delta => $nid) {
        if (($loaded[$nid] ?? NULL) instanceof NodeInterface) {
          $nodes_by_delta[$delta] = $loaded[$nid];
        }
      }
    }

    ksort($nodes_by_delta);
    return array_values($nodes_by_delta);
  }

  /**
   * Reads only a plausible node base-field value from a result row.
   */
  private function extractResultNodeId(ResultRow $row): ?int {
    $values = get_object_vars($row);
    $candidate_keys = ['nid', 'node_field_data_nid'];

    foreach ($values as $key => $value) {
      if ($key !== 'nid' && str_ends_with((string) $key, '_nid')) {
        $candidate_keys[] = (string) $key;
      }
    }

    foreach (array_unique($candidate_keys) as $key) {
      $value = $values[$key] ?? NULL;
      if ((is_int($value) || is_string($value))
        && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1) {
        $nid = filter_var(
          $value,
          FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 1]],
        );
        if (is_int($nid)) {
          return $nid;
        }
      }
    }

    return NULL;
  }

  /**
   * Returns eligible referenced tags when the reference field is viewable.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Published, accessible terms in field order.
   */
  private function getEligibleTerms(
    NodeInterface $node,
    AccountInterface $account,
    CacheableMetadata $cacheability,
  ): array {
    if (!$node->hasField(self::TAG_FIELD)) {
      return [];
    }

    $field = $node->get(self::TAG_FIELD);
    $field_access = $field->access('view', $account, TRUE);
    $cacheability->addCacheableDependency($field_access);
    if (!$field_access->isAllowed()) {
      return [];
    }

    $terms = [];
    foreach ($field->referencedEntities() as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      $cacheability->addCacheableDependency($term);
      $translated = $this->entityRepository->getTranslationFromContext($term);
      if ($translated instanceof TermInterface) {
        $term = $translated;
        $cacheability->addCacheableDependency($term);
      }

      $access = $term->access('view', $account, TRUE);
      $name_access = $term->get('name')->access('view', $account, TRUE);
      $cacheability
        ->addCacheableDependency($access)
        ->addCacheableDependency($name_access);
      if ($term->bundle() === self::TAG_VOCABULARY
        && $term->isPublished()
        && $access->isAllowed()
        && $name_access->isAllowed()) {
        $terms[(string) $term->id()] = $term;
      }
    }

    return array_values($terms);
  }

  /**
   * Loads and validates a selected tag without using result membership.
   */
  private function loadEligibleTerm(
    int $tid,
    AccountInterface $account,
    CacheableMetadata $cacheability,
  ): ?TermInterface {
    $term = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->load($tid);
    if (!$term instanceof TermInterface) {
      return NULL;
    }

    $cacheability->addCacheableDependency($term);
    $translated = $this->entityRepository->getTranslationFromContext($term);
    if ($translated instanceof TermInterface) {
      $term = $translated;
      $cacheability->addCacheableDependency($term);
    }

    $access = $term->access('view', $account, TRUE);
    $name_access = $term->get('name')->access('view', $account, TRUE);
    $cacheability
      ->addCacheableDependency($access)
      ->addCacheableDependency($name_access);
    return $term->bundle() === self::TAG_VOCABULARY
      && $term->isPublished()
      && $access->isAllowed()
      && $name_access->isAllowed()
        ? $term
        : NULL;
  }

  /**
   * Builds only an explicitly authored body summary as quiet plain text.
   *
   * @return array<string, mixed>
   *   A plain-text render array, or an empty render array.
   */
  private function buildExplicitSummary(
    NodeInterface $node,
    AccountInterface $account,
    CacheableMetadata $cacheability,
  ): array {
    if (!$node->hasField(self::SUMMARY_FIELD)) {
      return [];
    }

    $field = $node->get(self::SUMMARY_FIELD);
    $field_access = $field->access('view', $account, TRUE);
    $cacheability->addCacheableDependency($field_access);
    if (!$field_access->isAllowed()) {
      return [];
    }

    $item = $field->first();
    $summary = $item?->get('summary')->getString() ?? '';
    if (trim($summary) === '') {
      return [];
    }

    // Preserve only the explicitly authored summary text. Removing its markup
    // keeps headings, media, buttons, and other body structures out of the
    // deliberately flat homepage row without synthesizing an excerpt.
    $summary = preg_replace(
      '/<\/(?:p|div|li|h[1-6]|blockquote|dt|dd)>|<br\s*\/?>/i',
      '$0 ',
      $summary,
    ) ?? $summary;
    $plain_summary = preg_replace(
      '/\s+/',
      ' ',
      PlainTextOutput::renderFromHtml($summary),
    );
    if (!is_string($plain_summary) || trim($plain_summary) === '') {
      return [];
    }

    return [
      '#plain_text' => trim($plain_summary),
    ];
  }

  /**
   * Adds entity-type and bundle list cacheability for dynamic listings.
   */
  private function addListCacheability(CacheableMetadata $cacheability): void {
    $node_type = $this->entityTypeManager->getDefinition('node');
    $term_type = $this->entityTypeManager->getDefinition('taxonomy_term');
    $cacheability
      ->addCacheContexts($node_type->getListCacheContexts())
      ->addCacheContexts($term_type->getListCacheContexts())
      ->addCacheTags($node_type->getListCacheTags())
      ->addCacheTags($node_type->getBundleListCacheTags(self::ARTICLE_BUNDLE))
      ->addCacheTags($term_type->getListCacheTags())
      ->addCacheTags($term_type->getBundleListCacheTags(self::TAG_VOCABULARY));
  }

  /**
   * Parses the one supported theme query value.
   *
   * @param array<string, mixed> $query
   *   The request query values.
   *
   * @return array{present: bool, tid: ?int, invalid: bool}
   *   A normalized theme state.
   */
  private function parseThemeInput(array $query): array {
    if (!array_key_exists('theme', $query)) {
      return ['present' => FALSE, 'tid' => NULL, 'invalid' => FALSE];
    }

    $value = $query['theme'];
    if (!is_string($value)
      || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
      return ['present' => TRUE, 'tid' => NULL, 'invalid' => TRUE];
    }

    $tid = filter_var(
      $value,
      FILTER_VALIDATE_INT,
      ['options' => ['min_range' => 1]],
    );
    return is_int($tid)
      ? ['present' => TRUE, 'tid' => $tid, 'invalid' => FALSE]
      : ['present' => TRUE, 'tid' => NULL, 'invalid' => TRUE];
  }

  /**
   * Parses a bounded, canonical zero-based page query value.
   *
   * @param array<string, mixed> $query
   *   The request query values.
   */
  private function parsePageInput(array $query): int {
    $value = $query['page'] ?? NULL;
    if ($value === NULL) {
      return 0;
    }
    if (!is_string($value)
      || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
      return 0;
    }

    $page = filter_var(
      $value,
      FILTER_VALIDATE_INT,
      [
        'options' => [
          'min_range' => 0,
          'max_range' => self::MAX_PAGE,
        ],
      ],
    );
    return is_int($page) ? $page : 0;
  }

  /**
   * Builds a pager URL from only the normalized filter and page state.
   */
  private function buildPageUrl(int $page, ?string $theme): Url {
    $query = ['page' => (string) $page];
    if ($theme !== NULL) {
      $query = [
        'theme' => $theme,
        'page' => (string) $page,
      ];
    }

    return Url::fromUserInput('/accueil', ['query' => $query]);
  }

  /**
   * Builds a theme URL that resets paging and contains no other query keys.
   */
  private function buildThemeUrl(string $tid): Url {
    return Url::fromUserInput('/accueil', [
      'query' => ['theme' => $tid],
    ]);
  }

}
