<?php

declare(strict_types=1);

namespace Drupal\unisonges_resources\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\unisonges_resources\Manifest\ManifestRepository;
use Drupal\unisonges_resources\Manifest\ManifestValidationResult;
use Drupal\unisonges_resources\Manifest\ManifestValidator;

/**
 * Owns the single fail-closed predicate used by route and controller.
 */
final class ResourcesAccess implements AccessInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ManifestRepository $manifestRepository,
  ) {}

  public function config(): ImmutableConfig {
    return $this->configFactory->get('unisonges_resources.settings');
  }

  public function isOpen(ManifestValidationResult $manifest): bool {
    $config = $this->config();
    return !$config->hasOverrides() && self::allowsState(
      $config->get('enabled'),
      $config->get('manifest_fingerprint'),
      $manifest,
    );
  }

  /**
   * Pure predicate retained as a focused static-test seam.
   */
  public static function allowsState(
    mixed $enabled,
    mixed $configured_fingerprint,
    ManifestValidationResult $manifest,
  ): bool {
    return $enabled === TRUE
      && is_string($configured_fingerprint)
      && preg_match('/^[a-f0-9]{64}$/D', $configured_fingerprint) === 1
      && $manifest->isValid()
      && $manifest->isCatalogueApproved()
      && $manifest->publishedCount() >= 1
      && $manifest->publishedCount() <= ManifestValidator::MAX_PUBLISHED_RESOURCES
      && hash_equals($configured_fingerprint, $manifest->fingerprint());
  }

  /**
   * Route access callback; the menu inherits this decision automatically.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $config = $this->config();
    $allowed = $this->isOpen($this->manifestRepository->load());
    $result = $allowed
      ? AccessResult::allowed()
      : AccessResult::forbidden('The Resources hub is not activated for the current approved manifest.');

    return $result
      ->addCacheableDependency($config)
      ->addCacheTags(['unisonges_resources:manifest']);
  }

}
