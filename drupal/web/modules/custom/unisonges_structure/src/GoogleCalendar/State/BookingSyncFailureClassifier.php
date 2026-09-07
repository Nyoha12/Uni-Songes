<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Classifies safe failure facts without accepting raw remote details.
 */
final class BookingSyncFailureClassifier {

  public const TYPE_NETWORK = 'network';
  public const TYPE_TIMEOUT = 'timeout';
  public const TYPE_HTTP = 'http';
  public const TYPE_PAYLOAD = 'payload';
  public const TYPE_PERMANENT = 'permanent';

  private const RETRYABLE_403_REASONS = [
    'rate_limit',
    'quota',
    'temporary_authentication',
  ];

  /**
   * Classifies one allowlisted failure context.
   *
   * @return array{state: string, error_code: string, error_summary: string}
   *   A state target and fixed redacted error.
   */
  public function classify(string $type, ?int $http_status = NULL, bool $remote_result_possible = FALSE, string $reason = ''): array {
    if ($type === self::TYPE_NETWORK || $type === self::TYPE_TIMEOUT) {
      if ($remote_result_possible) {
        return $this->result(BookingSyncState::RECONCILIATION_REQUIRED, 'ambiguous_remote_result');
      }

      return $this->result(
        BookingSyncState::RETRYABLE_FAILURE,
        $type === self::TYPE_TIMEOUT ? 'timeout_before_request' : 'transient_network_failure'
      );
    }

    if ($type === self::TYPE_PAYLOAD) {
      return $this->result(BookingSyncState::PERMANENT_FAILURE, 'payload_invalid');
    }

    if ($type === self::TYPE_PERMANENT) {
      return $this->result(BookingSyncState::PERMANENT_FAILURE, 'permanent_error');
    }

    if ($type !== self::TYPE_HTTP || $http_status === NULL) {
      return $this->result(BookingSyncState::PERMANENT_FAILURE, 'unclassified_failure');
    }

    if ($http_status === 429) {
      return $this->result(BookingSyncState::RETRYABLE_FAILURE, 'rate_limited');
    }

    if ($http_status >= 500 && $http_status <= 599) {
      return $remote_result_possible
        ? $this->result(BookingSyncState::RECONCILIATION_REQUIRED, 'ambiguous_remote_result')
        : $this->result(BookingSyncState::RETRYABLE_FAILURE, 'remote_server_error');
    }

    if ($http_status === 401) {
      return $this->result(BookingSyncState::RETRYABLE_FAILURE, 'temporary_authentication');
    }

    if ($http_status === 403) {
      if (in_array($reason, self::RETRYABLE_403_REASONS, TRUE)) {
        $code = $reason === 'temporary_authentication' ? 'temporary_authentication' : 'rate_limited';
        return $this->result(BookingSyncState::RETRYABLE_FAILURE, $code);
      }

      return $this->result(BookingSyncState::PERMANENT_FAILURE, 'permission_denied');
    }

    if ($http_status === 404 || $http_status === 410 || $http_status === 409) {
      return $this->result(BookingSyncState::RECONCILIATION_REQUIRED, 'ambiguous_remote_result');
    }

    if ($http_status === 412) {
      return $this->result(BookingSyncState::RECONCILIATION_REQUIRED, 'etag_conflict');
    }

    if ($http_status === 400 || $http_status === 422) {
      return $this->result(BookingSyncState::PERMANENT_FAILURE, 'payload_invalid');
    }

    return $this->result(BookingSyncState::PERMANENT_FAILURE, 'permanent_error');
  }

  /**
   * Builds a fixed classification result.
   */
  private function result(string $state, string $error_code): array {
    $error = RedactedError::normalize($error_code);

    return [
      'state' => $state,
      'error_code' => $error['code'],
      'error_summary' => $error['summary'],
    ];
  }

}
