<?php

declare(strict_types=1);

namespace Drupal\unisonges_resources\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\unisonges_resources\Access\ResourcesAccess;
use Drupal\unisonges_resources\Manifest\ManifestRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds the flat, server-rendered Resources hub.
 */
final class ResourcesController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ManifestRepository $manifestRepository,
    private readonly ResourcesAccess $resourcesAccess,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('unisonges_resources.manifest_repository'),
      $container->get('unisonges_resources.access'),
      $container->get('language_manager'),
    );
  }

  /**
   * Renders all published resources or one exact manifest theme.
   */
  public function page(Request $request): array {
    $manifest = $this->manifestRepository->load();
    if (!$this->resourcesAccess->isOpen($manifest)) {
      throw new AccessDeniedHttpException();
    }

    $query = $request->query->all();
    if (!$this->hasOnlyOneThemeParameter($request, $query)) {
      throw new NotFoundHttpException('Unknown Resources filter.');
    }

    $active_theme = NULL;
    if (array_key_exists('theme', $query)) {
      if (!is_string($query['theme'])
        || $query['theme'] === ''
        || preg_match('//u', $query['theme']) !== 1
        || preg_match('/[\x00-\x1F\x7F]/u', $query['theme']) === 1
        || !in_array($query['theme'], $manifest->themes(), TRUE)) {
        throw new NotFoundHttpException('Unknown Resources theme.');
      }
      $active_theme = $query['theme'];
    }

    $groups = [];
    foreach ($manifest->themes() as $theme) {
      if ($active_theme !== NULL && $theme !== $active_theme) {
        continue;
      }
      $resources = $manifest->publicResourcesForTheme($theme);
      if ($resources !== []) {
        $groups[] = [
          'theme' => $theme,
          'resources' => $resources,
        ];
      }
    }
    if ($groups === []) {
      throw new NotFoundHttpException('The selected Resources theme is empty.');
    }

    $themes = [];
    foreach ($manifest->themes() as $theme) {
      $themes[] = [
        'label' => $theme,
        'url' => Url::fromRoute('unisonges_resources.page', [], [
          'query' => ['theme' => $theme],
          'language' => $this->languageManager->getCurrentLanguage(),
        ])->toString(),
      ];
    }

    $all_themes_url = Url::fromRoute('unisonges_resources.page', [], [
      'language' => $this->languageManager->getCurrentLanguage(),
    ])->toString();
    $attached = [
      'library' => ['unisonges_resources/hub'],
      'http_header' => [
        ['Referrer-Policy', 'no-referrer', TRUE],
      ],
    ];
    if ($active_theme !== NULL) {
      $attached['html_head'][] = [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'robots',
            'content' => 'noindex, follow',
          ],
        ],
        'unisonges_resources_theme_robots',
      ];
      $attached['http_header'][] = ['X-Robots-Tag', 'noindex, follow', TRUE];
    }

    return [
      '#theme' => 'unisonges_resources_page',
      '#groups' => $groups,
      '#themes' => $themes,
      '#active_theme' => $active_theme,
      '#all_themes_url' => $all_themes_url,
      '#attached' => $attached,
      '#cache' => [
        'contexts' => [
          'languages:language_interface',
          'route',
          'url.query_args',
        ],
        'tags' => [
          'config:unisonges_resources.settings',
          'unisonges_resources:manifest',
        ],
        'max-age' => Cache::PERMANENT,
      ],
    ];
  }

  /**
   * Refuses duplicate, array-valued, and arbitrary query parameters.
   *
   * Symfony's parsed query bag cannot distinguish repeated scalar keys, so
   * the raw query string is checked as well when the server provides it.
   */
  private function hasOnlyOneThemeParameter(Request $request, array $query): bool {
    if (array_diff(array_keys($query), ['theme']) !== []) {
      return FALSE;
    }

    $raw_query = (string) $request->server->get('QUERY_STRING', '');
    if ($raw_query === '') {
      return $query === [] || array_keys($query) === ['theme'];
    }

    $theme_parameters = 0;
    foreach (preg_split('/[&;]/D', $raw_query) ?: [] as $pair) {
      if ($pair === '') {
        return FALSE;
      }
      $encoded_key = explode('=', $pair, 2)[0];
      if (urldecode($encoded_key) !== 'theme') {
        return FALSE;
      }
      $theme_parameters++;
    }

    return $theme_parameters === 1 && array_keys($query) === ['theme'];
  }

}
