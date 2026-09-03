<?php

declare(strict_types=1);

namespace Drupal\unisonges_member_dashboard;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Attaches the dashboard placeholder after the canonical owner profile.
 */
final class MemberDashboardAttachment {

  public function __construct(
    private readonly CurrentRouteMatch $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly DashboardAccessPolicy $accessPolicy,
  ) {}

  /**
   * Adds an owner-varying lazy placeholder when every route guard passes.
   */
  public function attach(array &$build, EntityInterface $entity, string $view_mode): void {
    if (!$entity instanceof UserInterface || $view_mode !== 'full') {
      return;
    }

    $entity_access = $entity->access('view', $this->currentUser, TRUE);
    $decision_metadata = (new CacheableMetadata())
      ->setCacheContexts([
        'route',
        'request_format',
        'url.query_args:_wrapper_format',
        'user',
        'user.permissions',
        'languages:language_interface',
      ])
      ->addCacheableDependency($entity)
      ->addCacheableDependency($entity_access);
    // This must apply even when the dashboard is refused. Otherwise a
    // dashboard-free user.full build from another route can poison the owner
    // profile's entity render-cache entry before this hook runs again.
    CacheableMetadata::createFromRenderArray($build)
      ->merge($decision_metadata)
      ->applyTo($build);

    $route_user = $this->routeMatch->getParameter('user');
    if (!$route_user instanceof UserInterface) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $request_format = (string) $request?->getRequestFormat();
    if (!$this->accessPolicy->allows(
      (string) $this->routeMatch->getRouteName(),
      $request_format,
      $request?->query->has('_wrapper_format') ?? FALSE,
      $this->currentUser->isAuthenticated(),
      (int) $this->currentUser->id(),
      (int) $route_user->id(),
      (int) $entity->id(),
      $entity_access->isAllowed(),
    )) {
      return;
    }

    $dashboard = [
      '#lazy_builder' => [
        'unisonges_member_dashboard.builder:build',
        [],
      ],
      '#create_placeholder' => TRUE,
      '#weight' => 1000,
      '#cache' => [
        'keys' => ['unisonges_member_dashboard', 'owner_profile'],
        'contexts' => [
          'route',
          'request_format',
          'url.query_args:_wrapper_format',
          'user',
          'user.permissions',
          'languages:language_interface',
        ],
      ],
    ];

    $decision_metadata->applyTo($dashboard);

    $build['unisonges_member_dashboard'] = $dashboard;
  }

}
