<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Creates and validates opaque per-worker lease identities.
 */
final class WorkerIdentity {

  /**
   * Generates a 128-bit opaque worker identity.
   */
  public static function generate(): string {
    return 'worker_' . bin2hex(random_bytes(16));
  }

  /**
   * Returns whether a worker identity has the expected opaque format.
   */
  public static function isValid(string $worker_identity): bool {
    return preg_match('/^worker_[a-f0-9]{32}$/', $worker_identity) === 1;
  }

  /**
   * Prevents construction of this utility value object.
   */
  private function __construct() {
  }

}
