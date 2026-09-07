<?php

declare(strict_types=1);

namespace Drupal\unisonges_member_dashboard;

/**
 * Encodes the fail-closed owner-profile boundary in a testable form.
 */
final class DashboardAccessPolicy {

  public const CANONICAL_USER_ROUTE = 'entity.user.canonical';

  /**
   * Returns TRUE only for an accessible canonical profile owned by the viewer.
   */
  public function allows(
    string $route_name,
    string $request_format,
    bool $has_wrapper_format,
    bool $authenticated,
    int $current_uid,
    int $route_uid,
    int $rendered_uid,
    bool $entity_view_allowed,
  ): bool {
    return $route_name === self::CANONICAL_USER_ROUTE
      && $request_format === 'html'
      && !$has_wrapper_format
      && $authenticated
      && $current_uid > 0
      && $route_uid === $current_uid
      && $rendered_uid === $current_uid
      && $entity_view_allowed;
  }

}
