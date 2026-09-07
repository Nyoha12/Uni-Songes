<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Reviewed desired operations, independent from operational state.
 */
final class BookingSyncOperation {

  public const CREATE = 'create';
  public const UPDATE = 'update';
  public const CANCEL = 'cancel';

  /**
   * Returns every reviewed operation.
   */
  public static function all(): array {
    return [self::CREATE, self::UPDATE, self::CANCEL];
  }

  /**
   * Returns whether a value is a reviewed operation.
   */
  public static function isValid(string $operation): bool {
    return in_array($operation, self::all(), TRUE);
  }

  /**
   * Prevents construction of this constants-only value object.
   */
  private function __construct() {
  }

}
