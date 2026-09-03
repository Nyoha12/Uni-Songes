<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

use Drupal\Core\Database\Connection;

/**
 * Internal allowlisted read model for future permissioned diagnostics.
 */
final class BookingSyncDiagnosticReader {

  private const TABLE = 'unisonges_structure_booking_gcal_sync';

  private const SAFE_COLUMNS = [
    'id',
    'reservation_ref',
    'operation',
    'state',
    'attempt_count',
    'last_attempt_at',
    'next_retry_at',
    'lease_expires_at',
    'google_event_id',
    'last_error_code',
  ];

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $database;

  /**
   * Constructs the safe diagnostic reader.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Loads one initialized operational row as an allowlisted diagnostic.
   */
  public function load(int $record_id): ?array {
    if ($record_id <= 0) {
      return NULL;
    }

    try {
      $row = $this->database->select(self::TABLE, 'sync')
        ->fields('sync', self::SAFE_COLUMNS)
        ->condition('id', $record_id)
        ->condition('state', BookingSyncState::all(), 'IN')
        ->condition('operation', BookingSyncOperation::all(), 'IN')
        ->execute()
        ->fetchAssoc();
    }
    catch (\Throwable $e) {
      return NULL;
    }

    return is_array($row) ? self::safeView($row) : NULL;
  }

  /**
   * Converts a row to the exact safe operational read model.
   */
  public static function safeView(array $row): ?array {
    $id = (int) ($row['id'] ?? 0);
    $state = (string) ($row['state'] ?? '');
    $operation = (string) ($row['operation'] ?? '');
    if ($id <= 0 || !BookingSyncState::isValid($state) || !BookingSyncOperation::isValid($operation)) {
      return NULL;
    }

    $reference = (string) ($row['reservation_ref'] ?? '');
    if (preg_match('/^reservation_[a-f0-9]{64}$/', $reference) !== 1) {
      $reference = 'record_' . $id;
    }

    $error_code = isset($row['last_error_code']) && is_string($row['last_error_code'])
      ? $row['last_error_code']
      : NULL;
    if ($error_code !== NULL && !RedactedError::isAllowed($error_code)) {
      $error_code = 'unclassified_failure';
    }

    return [
      'record_id' => $id,
      'reservation_reference' => $reference,
      'operation' => $operation,
      'state' => $state,
      'attempts' => max(0, (int) ($row['attempt_count'] ?? 0)),
      'last_attempt' => self::nullableTimestamp($row['last_attempt_at'] ?? NULL),
      'next_retry' => self::nullableTimestamp($row['next_retry_at'] ?? NULL),
      'lease_expiry' => self::nullableTimestamp($row['lease_expires_at'] ?? NULL),
      'has_remote_event_id' => trim((string) ($row['google_event_id'] ?? '')) !== '',
      'error_code' => $error_code,
    ];
  }

  /**
   * Normalizes a nullable non-negative timestamp.
   */
  private static function nullableTimestamp($value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    return max(0, (int) $value);
  }

}
