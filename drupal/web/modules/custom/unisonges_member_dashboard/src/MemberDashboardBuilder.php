<?php

declare(strict_types=1);

namespace Drupal\unisonges_member_dashboard;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Unicode;
use Drupal\comment\CommentInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the private, read-only dashboard fragment.
 */
final class MemberDashboardBuilder implements TrustedCallbackInterface {

  use StringTranslationTrait;

  private const DISPLAY_LIMIT = 5;
  private const CANDIDATE_LIMIT = 25;
  private const RIGHTS_AUDIT_LIMIT = 100;
  private const RIGHTS_TABLE = 'unisonges_structure_course_to_pay_right';
  private const RESERVATION_WEBFORM = 'cours_particuliers_reservation';
  private const PROPOSAL_WEBFORM = 'forum_blog_proposal';
  private const CONTRIBUTION_PARENT_BUNDLES = [
    'article',
    'forum_topic',
  ];

  public function __construct(
    private readonly CurrentRouteMatch $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly DashboardAccessPolicy $accessPolicy,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly CurrencyFormatterInterface $currencyFormatter,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['build'];
  }

  /**
   * Builds the dashboard after repeating every owner and entity-access guard.
   */
  public function build(): array {
    $owner_context = $this->ownerContext();
    if ($owner_context === NULL) {
      return $this->emptyGuardedBuild();
    }

    [$account, $account_access] = $owner_context;
    $uid = (int) $account->id();
    $metadata = (new CacheableMetadata())
      ->setCacheContexts([
        'route',
        'request_format',
        'url.query_args:_wrapper_format',
        'user',
        'user.permissions',
        'languages:language_content',
        'languages:language_interface',
        'timezone',
      ])
      // Private submissions and the rights table have no complete invalidation
      // contract yet. The lazy placeholder keeps this fragment request-local.
      ->setCacheMaxAge(0)
      ->addCacheTags([
        'webform_submission_list',
        'commerce_order_list',
        'comment_list',
        'node_list',
        'config:webform.webform.' . self::RESERVATION_WEBFORM,
        'config:webform.webform.' . self::PROPOSAL_WEBFORM,
      ])
      ->addCacheableDependency($account)
      ->addCacheableDependency($account_access);

    $rights_audit = $this->buildRights($account, $metadata);
    $build = [
      '#theme' => 'unisonges_member_dashboard',
      '#reservations' => $this->buildReservations($uid, $metadata),
      '#rights' => $rights_audit['display'],
      '#orders' => $this->buildOrders(
        $uid,
        $rights_audit['verified_order_ids'],
        $metadata,
      ),
      '#proposals' => $this->buildProposals($uid, $metadata),
      '#contributions' => $this->buildContributions($uid, $metadata),
      '#attached' => [
        'library' => ['unisonges_member_dashboard/dashboard'],
      ],
    ];
    $metadata->applyTo($build);

    return $build;
  }

  /**
   * Returns the canonical owner entity and its normal view access result.
   */
  private function ownerContext(): ?array {
    $route_user = $this->routeMatch->getParameter('user');
    if (!$route_user instanceof UserInterface) {
      return NULL;
    }

    $account_access = $route_user->access('view', $this->currentUser, TRUE);
    $uid = (int) $this->currentUser->id();
    $request = $this->requestStack->getCurrentRequest();
    $request_format = (string) $request?->getRequestFormat();
    if (!$this->accessPolicy->allows(
      (string) $this->routeMatch->getRouteName(),
      $request_format,
      $request?->query->has('_wrapper_format') ?? FALSE,
      $this->currentUser->isAuthenticated(),
      $uid,
      (int) $route_user->id(),
      (int) $route_user->id(),
      $account_access->isAllowed(),
    )) {
      return NULL;
    }

    return [$route_user, $account_access];
  }

  /**
   * Returns an empty result that cannot be shared across access contexts.
   */
  private function emptyGuardedBuild(): array {
    return [
      '#cache' => [
        'contexts' => [
          'route',
          'request_format',
          'url.query_args:_wrapper_format',
          'user',
          'user.permissions',
          'languages:language_interface',
        ],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Builds the bounded reservation summary from an exact owner allowlist.
   */
  private function buildReservations(int $uid, CacheableMetadata $metadata): array {
    $items = [];
    try {
      $submissions = $this->loadOwnedSubmissions(
        self::RESERVATION_WEBFORM,
        $uid,
        self::DISPLAY_LIMIT,
        $metadata,
      );
      foreach ($submissions as $submission) {
        $data = $submission->getRawData();
        $reservation = DashboardValueMapper::parseReservation(
          is_string($data['reservation'] ?? NULL) ? $data['reservation'] : NULL,
        );
        $instrument_key = DashboardValueMapper::allowlistedString(
          $data['instrument'] ?? NULL,
          DashboardValueMapper::RESERVATION_INSTRUMENTS,
        );
        $mode_key = DashboardValueMapper::allowlistedString(
          $data['mode_cours'] ?? NULL,
          DashboardValueMapper::RESERVATION_MODES,
        );
        $platform_key = $mode_key === 'visio'
          ? DashboardValueMapper::allowlistedString(
            $data['plateforme_visio'] ?? NULL,
            DashboardValueMapper::RESERVATION_PLATFORMS,
          )
          : NULL;
        $state = DashboardValueMapper::reservationState($reservation);

        $items[] = [
          'title' => $this->instrumentLabel($instrument_key),
          'submitted' => $this->formatDate($submission->getCreatedTime()),
          'scheduled' => $reservation === NULL
            ? NULL
            : $this->formatDateTime($reservation['start']),
          'mode' => $this->modeLabel($mode_key),
          'platform' => $this->platformLabel($platform_key),
          'status' => $state === 'registered'
            ? $this->t('Enregistrée')
            : $this->t('Non active'),
          'status_key' => $state,
        ];
      }
    }
    catch (\Throwable) {
      $this->logger->warning('The owner reservation summary could not be built.');
      return [];
    }

    return $items;
  }

  /**
   * Builds paid aggregate and independently verified pay-on-site rights.
   */
  private function buildRights(UserInterface $account, CacheableMetadata $metadata): array {
    $display = [
      'paid' => NULL,
      'pay_on_site' => NULL,
    ];

    if ($account->hasField('field_seances_restantes')
      && $this->fieldsViewable($account, ['field_seances_restantes'], $metadata)
      && !$account->get('field_seances_restantes')->isEmpty()) {
      $paid_count = DashboardValueMapper::positiveInteger(
        $account->get('field_seances_restantes')->value,
      );
      if ($paid_count !== NULL) {
        $expiry_readable = !$account->hasField('field_pack_expire_le')
          || $this->fieldsViewable($account, ['field_pack_expire_le'], $metadata);
        $expiry_value = $expiry_readable && $account->hasField('field_pack_expire_le')
          ? trim((string) ($account->get('field_pack_expire_le')->value ?? ''))
          : '';
        $expiry = $expiry_readable
          ? $this->validUsableExpiry($expiry_value)
          : NULL;
        if ($expiry_readable && ($expiry_value === '' || $expiry !== NULL)) {
          $display['paid'] = [
            'count' => $paid_count,
            'expiry' => $expiry === NULL ? NULL : $this->formatCalendarDate($expiry),
          ];
        }
      }
    }

    $pay_on_site = $this->auditPayOnSiteRights((int) $account->id(), $metadata);
    if ($pay_on_site['count'] > 0) {
      $display['pay_on_site'] = ['count' => $pay_on_site['count']];
    }

    return [
      'display' => $display,
      'verified_order_ids' => $pay_on_site['verified_order_ids'],
    ];
  }

  /**
   * Audits each custom-table row against its owner and source entities.
   */
  private function auditPayOnSiteRights(int $uid, CacheableMetadata $metadata): array {
    $empty = ['count' => 0, 'verified_order_ids' => []];
    try {
      if (!$this->database->schema()->tableExists(self::RIGHTS_TABLE)) {
        return $empty;
      }

      $rows = $this->database->select(self::RIGHTS_TABLE, 'r')
        ->fields('r', [
          'order_id',
          'uid',
          'source_order_item_id',
          'credit_index',
          'product_bundle',
          'remaining_to_pay_credits',
          'webform_submission_id',
          'status',
          'consumed',
          'paid',
          'cancelled',
        ])
        ->condition('uid', $uid)
        ->condition('status', 'pending_payment')
        ->condition('remaining_to_pay_credits', 0, '>')
        ->orderBy('created', 'ASC')
        ->orderBy('id', 'ASC')
        ->range(0, self::RIGHTS_AUDIT_LIMIT + 1)
        ->execute()
        ->fetchAll();

      if (count($rows) > self::RIGHTS_AUDIT_LIMIT) {
        $this->logger->warning('The bounded owner rights audit exceeded its safe limit.');
        return $empty;
      }

      $order_ids = [];
      foreach ($rows as $row) {
        $order_id = DashboardValueMapper::positiveInteger((string) $row->order_id);
        if ($order_id !== NULL) {
          $order_ids[$order_id] = $order_id;
        }
      }
      $orders = $order_ids === []
        ? []
        : $this->entityTypeManager
          ->getStorage('commerce_order')
          ->loadMultiple($order_ids);

      $count = 0;
      $verified_order_ids = [];
      foreach ($rows as $row) {
        $validated = $this->validatePayOnSiteRow(
          $row,
          $uid,
          $orders,
          $metadata,
        );
        if ($validated === NULL) {
          continue;
        }

        $count += $validated['usable_count'];
        $verified_order_ids[(int) $row->order_id] = TRUE;
      }

      return [
        'count' => $count,
        'verified_order_ids' => $verified_order_ids,
      ];
    }
    catch (\Throwable) {
      $this->logger->warning('The owner pay-on-site rights summary could not be built.');
      return $empty;
    }
  }

  /**
   * Validates one usable right used by the dashboard's rights/order mappings.
   *
   * @return array{usable_count: int}|null
   *   A positive count for a coherent pending row, or NULL for any malformed
   *   or cross-owner source chain.
   */
  private function validatePayOnSiteRow(
    object $row,
    int $uid,
    array $orders,
    CacheableMetadata $metadata,
  ): ?array {
    $order_id = DashboardValueMapper::positiveInteger((string) $row->order_id);
    $item_id = DashboardValueMapper::positiveInteger((string) $row->source_order_item_id);
    $credit_index = DashboardValueMapper::positiveInteger((string) $row->credit_index);
    $remaining_value = (string) $row->remaining_to_pay_credits;
    $remaining = preg_match('/^\d+$/D', $remaining_value) === 1
      ? (int) $remaining_value
      : NULL;
    $status = (string) $row->status;
    if ($order_id === NULL
      || $item_id === NULL
      || $credit_index === NULL
      || $remaining === NULL
      || (int) $row->uid !== $uid
      || $status !== 'pending_payment'
      || $remaining <= 0) {
      return NULL;
    }

    $order = $orders[$order_id] ?? NULL;
    if (!$order instanceof OrderInterface
      || (int) $order->getCustomerId() !== $uid) {
      return NULL;
    }

    $order_access = $order->access('view', $this->currentUser, TRUE);
    $metadata
      ->addCacheableDependency($order)
      ->addCacheableDependency($order_access);
    if (!$order_access->isAllowed()) {
      return NULL;
    }
    if (!$this->fieldsViewable($order, [
      'state',
      'total_price',
      'total_paid',
      'balance',
      'payment_gateway',
      'order_items',
    ], $metadata)
      || $order->getState()->getId() !== 'completed'
      || $order->isPaid()
      || !$this->orderUsesManualGateway($order, $metadata)) {
      return NULL;
    }

    $source_item = NULL;
    foreach ($order->getItems() as $order_item) {
      if ($order_item instanceof OrderItemInterface
        && (int) $order_item->id() === $item_id) {
        $source_item = $order_item;
        break;
      }
    }
    if (!$source_item instanceof OrderItemInterface
      || (int) $source_item->getOrderId() !== $order_id) {
      return NULL;
    }
    $item_access = $source_item->access('view', $this->currentUser, TRUE);
    $metadata
      ->addCacheableDependency($source_item)
      ->addCacheableDependency($item_access);
    if (!$item_access->isAllowed()) {
      return NULL;
    }
    if (!$this->fieldsViewable(
      $source_item,
      ['purchased_entity', 'quantity'],
      $metadata,
    )) {
      return NULL;
    }

    $variation = $source_item->getPurchasedEntity();
    if (!$variation instanceof EntityInterface || !method_exists($variation, 'getProduct')) {
      return NULL;
    }
    $product = $variation->getProduct();
    if (!$product instanceof EntityInterface) {
      return NULL;
    }
    $metadata
      ->addCacheableDependency($variation)
      ->addCacheableDependency($product);
    $actual_bundle = DashboardValueMapper::allowlistedString(
      $product->bundle(),
      DashboardValueMapper::COURSE_PRODUCT_BUNDLES,
    );
    if ($actual_bundle === NULL || (string) $row->product_bundle !== $actual_bundle) {
      return NULL;
    }
    $submission_reference = $row->webform_submission_id ?? NULL;
    if ($submission_reference !== NULL) {
      $submission_id = DashboardValueMapper::positiveInteger((string) $submission_reference);
      if ($submission_id === NULL) {
        return NULL;
      }
      $submission = $this->entityTypeManager
        ->getStorage('webform_submission')
        ->load($submission_id);
      if ($submission instanceof WebformSubmissionInterface) {
        $metadata->addCacheableDependency($submission);
      }
      $webform = $submission instanceof WebformSubmissionInterface
        ? $submission->getWebform()
        : NULL;
      if ($webform !== NULL) {
        $metadata->addCacheableDependency($webform);
      }
      if (!$submission instanceof WebformSubmissionInterface
        || (int) $submission->getOwnerId() !== $uid
        || $submission->isDraft()
        || $webform === NULL
        || $webform->id() !== self::RESERVATION_WEBFORM) {
        return NULL;
      }
      // A coherent referenced submission is still a consumed, not usable,
      // right. The checks above prevent a cross-owner reference being trusted.
      return NULL;
    }

    $credit_capacity = DashboardValueMapper::courseCreditCapacity(
      $actual_bundle,
      trim((string) $source_item->getQuantity()),
    );
    if ($credit_capacity === NULL
      || !DashboardValueMapper::isUsablePayOnSiteShape(
        $status,
        $remaining,
        $submission_reference,
        $row->consumed ?? NULL,
        $row->paid ?? NULL,
        $row->cancelled ?? NULL,
        $credit_index,
        $credit_capacity,
      )) {
      return NULL;
    }

    return ['usable_count' => $remaining];
  }

  /**
   * Builds a bounded list of owner-accessible Commerce orders.
   */
  private function buildOrders(
    int $uid,
    array $verified_right_order_ids,
    CacheableMetadata $metadata,
  ): array {
    $items = [];
    try {
      $storage = $this->entityTypeManager->getStorage('commerce_order');
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('uid', $uid)
        ->condition('state', 'draft', '<>')
        ->sort('created', 'DESC')
        ->sort('order_id', 'DESC')
        ->range(0, self::CANDIDATE_LIMIT)
        ->execute();

      foreach ($storage->loadMultiple($ids) as $order) {
        if (!$order instanceof OrderInterface
          || (int) $order->getCustomerId() !== $uid
          || $order->getState()->getId() === 'draft') {
          continue;
        }

        $order_access = $order->access('view', $this->currentUser, TRUE);
        $metadata
          ->addCacheableDependency($order)
          ->addCacheableDependency($order_access);
        if (!$order_access->isAllowed()) {
          continue;
        }
        if (!$this->fieldsViewable($order, [
          'order_number',
          'created',
          'placed',
          'total_price',
          'total_paid',
          'balance',
          'state',
        ], $metadata)) {
          continue;
        }

        $state = $order->getState()->getId();
        $total = $order->getTotalPrice();
        $has_positive_total = $total !== NULL && $total->isPositive();
        $is_paid = $has_positive_total && $order->isPaid();
        $is_manual = $has_positive_total
          && $this->orderUsesManualGateway($order, $metadata);
        $display_state = DashboardValueMapper::orderState(
          $state,
          $is_paid,
          $is_manual,
          isset($verified_right_order_ids[(int) $order->id()]),
        );
        $placed = $order->getPlacedTime();
        $created = $order->getCreatedTime();
        $formatted_total = NULL;
        if ($total !== NULL) {
          $formatted_total = $this->currencyFormatter->format(
            $total->getNumber(),
            $total->getCurrencyCode(),
          );
        }

        $items[] = [
          'number' => trim((string) $order->getOrderNumber()),
          'date' => $this->formatDate((int) ($placed ?: $created)),
          'date_label' => $placed
            ? $this->t('Commandée le')
            : $this->t('Créée le'),
          'total' => $formatted_total,
          'status' => $this->orderStatusLabel($display_state),
          'status_key' => $display_state,
          'url' => $this->ownerOrderUrl($order, $uid, $metadata),
        ];
        if (count($items) >= self::DISPLAY_LIMIT) {
          break;
        }
      }
    }
    catch (\Throwable) {
      $this->logger->warning('The owner order summary could not be built.');
      return [];
    }

    return $items;
  }

  /**
   * Builds the bounded private proposal summary.
   */
  private function buildProposals(int $uid, CacheableMetadata $metadata): array {
    $items = [];
    try {
      $submissions = $this->loadOwnedSubmissions(
        self::PROPOSAL_WEBFORM,
        $uid,
        self::CANDIDATE_LIMIT,
        $metadata,
      );
      foreach ($submissions as $submission) {
        $data = $submission->getRawData();
        $type_key = DashboardValueMapper::allowlistedString(
          $data['proposal_type'] ?? NULL,
          DashboardValueMapper::PROPOSAL_TYPES,
        );
        $title = is_string($data['title'] ?? NULL)
          ? trim($data['title'])
          : '';
        if ($type_key === NULL || $title === '') {
          continue;
        }

        $items[] = [
          'title' => Unicode::truncate($title, 160, TRUE, TRUE),
          'type' => $this->proposalTypeLabel($type_key),
          'submitted' => $this->formatDate($submission->getCreatedTime()),
          'status' => $this->t('Reçue'),
        ];
        if (count($items) >= self::DISPLAY_LIMIT) {
          break;
        }
      }
    }
    catch (\Throwable) {
      $this->logger->warning('The owner proposal summary could not be built.');
      return [];
    }

    return $items;
  }

  /**
   * Builds published owner comments whose parents remain accessible.
   */
  private function buildContributions(int $uid, CacheableMetadata $metadata): array {
    $items = [];
    try {
      $storage = $this->entityTypeManager->getStorage('comment');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->condition('status', CommentInterface::PUBLISHED)
        ->condition('entity_type', 'node')
        ->condition('field_name', 'comment')
        ->sort('created', 'DESC')
        ->sort('cid', 'DESC')
        ->range(0, self::CANDIDATE_LIMIT)
        ->execute();

      foreach ($storage->loadMultiple($ids) as $comment) {
        if (!$comment instanceof CommentInterface
          || (int) $comment->getOwnerId() !== $uid
          || !$comment->isPublished()
          || $comment->getCommentedEntityTypeId() !== 'node') {
          continue;
        }

        $comment_access = $comment->access('view', $this->currentUser, TRUE);
        $metadata
          ->addCacheableDependency($comment)
          ->addCacheableDependency($comment_access);
        if (!$comment_access->isAllowed()) {
          continue;
        }

        if ($comment->hasParentComment()) {
          $parent_comment = $comment->getParentComment();
          if (!$parent_comment instanceof CommentInterface
            || !$parent_comment->isPublished()) {
            continue;
          }
          $parent_comment_access = $parent_comment->access(
            'view',
            $this->currentUser,
            TRUE,
          );
          $metadata
            ->addCacheableDependency($parent_comment)
            ->addCacheableDependency($parent_comment_access);
          if (!$parent_comment_access->isAllowed()) {
            continue;
          }
        }

        $parent = $comment->getCommentedEntity();
        if (!$parent instanceof NodeInterface
          || !$parent->isPublished()
          || !in_array($parent->bundle(), self::CONTRIBUTION_PARENT_BUNDLES, TRUE)) {
          continue;
        }
        $parent_access = $parent->access('view', $this->currentUser, TRUE);
        $metadata
          ->addCacheableDependency($parent)
          ->addCacheableDependency($parent_access);
        if (!$parent_access->isAllowed()) {
          continue;
        }

        $url = $this->accessibleUrl($parent->toUrl('canonical'), $metadata);
        if ($url === NULL
          || !$this->fieldsViewable($comment, ['created', 'comment_body'], $metadata)
          || !$this->fieldsViewable($parent, ['title'], $metadata)) {
          continue;
        }
        $body_item = $comment->get('comment_body')->first();
        $processed = $body_item === NULL ? '' : (string) ($body_item->processed ?? '');
        $plain = trim((string) preg_replace(
          '/\s+/u',
          ' ',
          PlainTextOutput::renderFromHtml($processed),
        ));
        if ($plain === '') {
          continue;
        }

        $items[] = [
          'date' => $this->formatDate($comment->getCreatedTime()),
          'excerpt' => Unicode::truncate($plain, 180, TRUE, TRUE),
          'parent_title' => Unicode::truncate((string) $parent->label(), 160, TRUE, TRUE),
          'parent_type' => $parent->bundle() === 'forum_topic'
            ? $this->t('Discussion')
            : $this->t('Article'),
          'url' => $url,
        ];
        if (count($items) >= self::DISPLAY_LIMIT) {
          break;
        }
      }
    }
    catch (\Throwable) {
      $this->logger->warning('The owner contribution summary could not be built.');
      return [];
    }

    return $items;
  }

  /**
   * Loads only complete submissions for one exact form and owner.
   */
  private function loadOwnedSubmissions(
    string $webform_id,
    int $uid,
    int $limit,
    CacheableMetadata $metadata,
  ): array {
    if (!$this->currentUser->isAuthenticated()
      || $uid <= 0
      || $uid !== (int) $this->currentUser->id()
      || $limit < 1
      || $limit > self::CANDIDATE_LIMIT
      || !in_array($webform_id, [self::RESERVATION_WEBFORM, self::PROPOSAL_WEBFORM], TRUE)) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('webform_submission');
    // This service is the narrow owner-summary authorization boundary. Generic
    // Webform result access intentionally remains closed to members.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', $webform_id)
      ->condition('uid', $uid)
      ->condition('in_draft', 0)
      ->sort('created', 'DESC')
      ->sort('sid', 'DESC')
      ->range(0, $limit)
      ->execute();

    $submissions = [];
    foreach ($storage->loadMultiple($ids) as $submission) {
      $webform = $submission instanceof WebformSubmissionInterface
        ? $submission->getWebform()
        : NULL;
      if (!$submission instanceof WebformSubmissionInterface
        || (int) $submission->getOwnerId() !== $uid
        || $submission->isDraft()
        || $webform === NULL
        || $webform->id() !== $webform_id) {
        continue;
      }
      $metadata
        ->addCacheableDependency($submission)
        ->addCacheableDependency($webform);
      $submissions[] = $submission;
    }

    return $submissions;
  }

  /**
   * Returns a future-or-current expiry, or NULL when invalid or expired.
   */
  private function validUsableExpiry(string $value): ?\DateTimeImmutable {
    return DashboardValueMapper::usableExpiry(
      $value,
      $this->time->getCurrentTime(),
      date_default_timezone_get(),
    );
  }

  /**
   * Detects only the reviewed manual gateway marker on the order itself.
   */
  private function orderUsesManualGateway(
    OrderInterface $order,
    CacheableMetadata $metadata,
  ): bool {
    if (!$order->hasField('payment_gateway')
      || !$this->fieldsViewable($order, ['payment_gateway'], $metadata)
      || $order->get('payment_gateway')->isEmpty()) {
      return FALSE;
    }

    $gateway_item = $order->get('payment_gateway')->first();
    $gateway_id = trim((string) ($gateway_item?->target_id ?? ''));
    $gateway = $gateway_item?->entity;
    if ($gateway instanceof EntityInterface) {
      $metadata->addCacheableDependency($gateway);
      if (method_exists($gateway, 'getPluginId') && $gateway->getPluginId() === 'manual') {
        return TRUE;
      }
    }

    return $gateway_id === 'manual';
  }

  /**
   * Builds the existing customer order URL only when route access allows it.
   */
  private function ownerOrderUrl(
    OrderInterface $order,
    int $uid,
    CacheableMetadata $metadata,
  ): ?string {
    $url = Url::fromRoute('entity.commerce_order.user_view', [
      'user' => $uid,
      'commerce_order' => $order->id(),
    ]);

    return $this->accessibleUrl($url, $metadata);
  }

  /**
   * Returns a generated URL only after access and cacheability are collected.
   */
  private function accessibleUrl(Url $url, CacheableMetadata $metadata): ?string {
    $url_access = $url->access($this->currentUser, TRUE);
    $metadata->addCacheableDependency($url_access);
    if (!$url_access->isAllowed()) {
      return NULL;
    }

    $generated_url = $url->toString(TRUE);
    $metadata->addCacheableDependency($generated_url);
    return $generated_url->getGeneratedUrl();
  }

  /**
   * Applies normal field access before reading any displayed entity value.
   */
  private function fieldsViewable(
    EntityInterface $entity,
    array $field_names,
    CacheableMetadata $metadata,
  ): bool {
    if (!$entity instanceof FieldableEntityInterface) {
      return FALSE;
    }
    foreach ($field_names as $field_name) {
      if (!$entity->hasField($field_name)) {
        return FALSE;
      }
      $field_access = $entity->get($field_name)->access(
        'view',
        $this->currentUser,
        TRUE,
      );
      $metadata->addCacheableDependency($field_access);
      if (!$field_access->isAllowed()) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Formats a Unix timestamp without exposing storage values.
   */
  private function formatDate(int $timestamp): string {
    return $this->dateFormatter->format(
      $timestamp,
      'custom',
      'j F Y',
      DashboardValueMapper::BOOKING_TIMEZONE,
    );
  }

  /**
   * Formats a validated date or date/time in the booking timezone.
   */
  private function formatDateTime(\DateTimeImmutable $date): string {
    return $this->dateFormatter->format(
      $date->getTimestamp(),
      'custom',
      'j F Y \à H\hi',
      DashboardValueMapper::BOOKING_TIMEZONE,
    );
  }

  /**
   * Formats a date-only value without moving it across a timezone boundary.
   */
  private function formatCalendarDate(\DateTimeImmutable $date): string {
    return $this->dateFormatter->format(
      $date->getTimestamp(),
      'custom',
      'j F Y',
      $date->getTimezone()->getName(),
    );
  }

  /**
   * Returns a translated instrument label for an allowlisted key.
   */
  private function instrumentLabel(?string $key): mixed {
    return match ($key) {
      'guimbarde' => $this->t('Guimbarde'),
      'didgeridoo' => $this->t('Didgeridoo'),
      default => $this->t('Réservation'),
    };
  }

  /**
   * Returns a translated course-mode label for an allowlisted key.
   */
  private function modeLabel(?string $key): mixed {
    return match ($key) {
      'visio' => $this->t('Visio'),
      'studio' => $this->t('Au studio à Aubervilliers'),
      'domicile' => $this->t('À domicile'),
      default => NULL,
    };
  }

  /**
   * Returns a translated platform label for an allowlisted key.
   */
  private function platformLabel(?string $key): mixed {
    return match ($key) {
      'zoom' => $this->t('Zoom'),
      'google_meet' => $this->t('Google Meet'),
      'skype' => $this->t('Skype'),
      'whatsapp' => $this->t('WhatsApp'),
      'autre' => $this->t('Autre'),
      default => NULL,
    };
  }

  /**
   * Returns a translated proposal-type label for an allowlisted key.
   */
  private function proposalTypeLabel(string $key): mixed {
    return match ($key) {
      'idea' => $this->t('Idée'),
      'discussion_topic' => $this->t('Sujet de discussion'),
      'article_theme' => $this->t('Thème d’article'),
    };
  }

  /**
   * Returns a translated conservative order-status label.
   */
  private function orderStatusLabel(string $state): mixed {
    return match ($state) {
      'paid' => $this->t('Payée'),
      'pay_on_site' => $this->t('À régler sur place'),
      'cancelled' => $this->t('Annulée'),
      default => $this->t('En cours'),
    };
  }

}
