<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Reviewed public operational states for one booking mapping.
 */
final class BookingSyncState {

  public const QUEUED = 'queued';
  public const PROCESSING = 'processing';
  public const SYNCED = 'synced';
  public const RETRYABLE_FAILURE = 'retryable_failure';
  public const PERMANENT_FAILURE = 'permanent_failure';
  public const CANCEL_PENDING = 'cancel_pending';
  public const CANCELLED = 'cancelled';
  public const RECONCILIATION_REQUIRED = 'reconciliation_required';

  /**
   * Returns every reviewed state.
   */
  public static function all(): array {
    return [
      self::QUEUED,
      self::PROCESSING,
      self::SYNCED,
      self::RETRYABLE_FAILURE,
      self::PERMANENT_FAILURE,
      self::CANCEL_PENDING,
      self::CANCELLED,
      self::RECONCILIATION_REQUIRED,
    ];
  }

  /**
   * Returns whether a value is a reviewed state.
   */
  public static function isValid(string $state): bool {
    return in_array($state, self::all(), TRUE);
  }

  /**
   * Prevents construction of this constants-only value object.
   */
  private function __construct() {
  }

}
