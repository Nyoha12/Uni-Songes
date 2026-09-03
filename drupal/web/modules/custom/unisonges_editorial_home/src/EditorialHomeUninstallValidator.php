<?php

declare(strict_types=1);

namespace Drupal\unisonges_editorial_home;

use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\State\StateInterface;

/**
 * Keeps the coupled View, block, Body, state, and module rollback atomic.
 */
final class EditorialHomeUninstallValidator implements ModuleUninstallValidatorInterface {

  private const MODULE = 'unisonges_editorial_home';

  private const ROLLBACK_STATE_KEY = 'unisonges_editorial_home.rollback.v1';

  /**
   * Constructs the uninstall validator.
   */
  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    if ($module !== self::MODULE) {
      return [];
    }
    $sentinel = new \stdClass();
    if ($this->state->get(self::ROLLBACK_STATE_KEY, $sentinel) === $sentinel) {
      // A bare or incomplete module activation has no coupled rollback copy.
      // Let Drupal remove it instead of trapping an operator in a partial state.
      return [];
    }
    if ($this->isPreflightAuthorized() || $this->isRollbackAuthorized()) {
      return [];
    }
    return [
      'Use the editorial-home dry-run-first installer for its exact coupled rollback',
    ];
  }

  /**
   * Allows the helper to run every uninstall validator without writing.
   */
  private function isPreflightAuthorized(): bool {
    $authorization = $GLOBALS[
      'UNISONGES_EDITORIAL_HOME_UNINSTALL_VALIDATION_AUTHORIZATION'
    ] ?? NULL;
    return PHP_SAPI === 'cli'
      && is_array($authorization)
      && array_keys($authorization) === [
        'version',
        'feature',
        'mode',
        'action',
        'site_origin',
        'git_head',
        'node_id',
      ]
      && $authorization['version'] === 1
      && $authorization['feature'] === self::MODULE
      && is_string($authorization['mode'])
      && in_array($authorization['mode'], ['dry-run', 'apply'], TRUE)
      && getenv('UNISONGES_EDITORIAL_HOME_MODE') === $authorization['mode']
      && $authorization['action'] === 'rollback'
      && getenv('UNISONGES_EDITORIAL_HOME_ACTION') === 'rollback'
      && is_string($authorization['site_origin'])
      && getenv('UNISONGES_EDITORIAL_HOME_SITE_URI') === $authorization['site_origin']
      && is_string($authorization['git_head'])
      && preg_match('/^[a-f0-9]{40}$/D', $authorization['git_head']) === 1
      && hash_equals(
        getenv('UNISONGES_EDITORIAL_HOME_GIT_HEAD') ?: '',
        $authorization['git_head'],
      )
      && is_int($authorization['node_id'])
      && $authorization['node_id'] > 0;
  }

  /**
   * Allows only the locked helper process to perform the actual rollback.
   */
  private function isRollbackAuthorized(): bool {
    $authorization = $GLOBALS[
      'UNISONGES_EDITORIAL_HOME_BODY_TRANSITION_AUTHORIZATION'
    ] ?? NULL;
    return PHP_SAPI === 'cli'
      && is_array($authorization)
      && array_keys($authorization) === [
        'version',
        'action',
        'plan_token',
        'git_head',
        'node_id',
        'expected_body',
        'target_body',
      ]
      && $authorization['version'] === 1
      && $authorization['action'] === 'rollback'
      && getenv('UNISONGES_EDITORIAL_HOME_MODE') === 'apply'
      && getenv('UNISONGES_EDITORIAL_HOME_ACTION') === 'rollback'
      && is_string($authorization['plan_token'])
      && preg_match('/^[a-f0-9]{64}$/D', $authorization['plan_token']) === 1
      && hash_equals(
        getenv('UNISONGES_EDITORIAL_HOME_PLAN_TOKEN') ?: '',
        $authorization['plan_token'],
      )
      && is_string($authorization['git_head'])
      && preg_match('/^[a-f0-9]{40}$/D', $authorization['git_head']) === 1
      && hash_equals(
        getenv('UNISONGES_EDITORIAL_HOME_GIT_HEAD') ?: '',
        $authorization['git_head'],
      )
      && is_int($authorization['node_id'])
      && $authorization['node_id'] > 0
      && is_array($authorization['expected_body'])
      && is_array($authorization['target_body'])
      && \defined('UNISONGES_EDITORIAL_HOME_BODY_TRANSITION_AUTHORIZATION')
      && is_string(\constant(
        'UNISONGES_EDITORIAL_HOME_BODY_TRANSITION_AUTHORIZATION',
      ))
      && hash_equals(
        $authorization['plan_token'],
        \constant('UNISONGES_EDITORIAL_HOME_BODY_TRANSITION_AUTHORIZATION'),
      );
  }

}
