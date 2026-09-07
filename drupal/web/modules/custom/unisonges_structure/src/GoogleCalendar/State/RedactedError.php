<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Allowlisted operational errors with fixed operator-safe summaries.
 */
final class RedactedError {

  private const SUMMARIES = [
    'transient_network_failure' => 'A transient network failure occurred before a remote result was possible.',
    'timeout_before_request' => 'The operation timed out before a remote request could be dispatched.',
    'rate_limited' => 'The remote service requested bounded backoff.',
    'remote_server_error' => 'The remote service reported a temporary server condition.',
    'temporary_authentication' => 'A temporary authentication condition prevented the operation.',
    'ambiguous_remote_result' => 'The remote outcome is uncertain and requires reconciliation.',
    'etag_conflict' => 'The stored remote version no longer matches.',
    'payload_invalid' => 'The reviewed operational payload was invalid.',
    'permission_denied' => 'The operation was denied by a non-temporary policy condition.',
    'permanent_error' => 'A non-retryable operational condition requires review.',
    'unclassified_failure' => 'An unclassified condition failed closed and requires review.',
    'retry_exhausted' => 'The bounded automatic retry budget was exhausted.',
    'lease_expired_ambiguous' => 'An expired processing lease has an uncertain remote outcome.',
    'legacy_failure_unresolved' => 'A legacy failure remains unresolved and requires review.',
    'legacy_dry_run_unverified' => 'A legacy dry-run result did not prove remote convergence.',
    'legacy_synced_unverified' => 'A legacy synced result lacks sufficient reconciliation evidence.',
    'legacy_status_ambiguous' => 'The legacy status cannot be mapped safely without review.',
    'legacy_operation_ambiguous' => 'The legacy operation cannot be mapped safely without review.',
    'legacy_event_id_invalid' => 'The preserved legacy event identity is malformed and requires review.',
    'legacy_source_identity_invalid' => 'The legacy source identity is missing or malformed and requires review.',
  ];

  /**
   * Returns whether a code is safe to persist and expose operationally.
   */
  public static function isAllowed(string $code): bool {
    return isset(self::SUMMARIES[$code]);
  }

  /**
   * Returns the fixed summary for a code, failing closed for unknown input.
   */
  public static function normalize(string $code): array {
    if (!self::isAllowed($code)) {
      $code = 'unclassified_failure';
    }

    return [
      'code' => $code,
      'summary' => self::SUMMARIES[$code],
    ];
  }

  /**
   * Prevents construction of this constants-only value object.
   */
  private function __construct() {
  }

}
