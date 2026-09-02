<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Persists explicit booking-sync lifecycle mutations with atomic CAS updates.
 */
final class BookingSyncStateRepository {

  private const TABLE = 'unisonges_structure_booking_gcal_sync';

  private const OPERATIONAL_COLUMNS = [
    'id',
    'reservation_ref',
    'operation',
    'desired_revision',
    'processing_operation',
    'state',
    'generation',
    'attempt_count',
    'first_queued_at',
    'retry_window_started_at',
    'last_attempt_at',
    'next_retry_at',
    'claim_mode',
    'lease_owner',
    'lease_expires_at',
    'lease_generation',
    'last_successful_sync_at',
    'google_event_id',
    'remote_etag',
    'last_error_code',
    'last_error_summary',
    'created',
    'changed',
  ];

  private const MUTABLE_COLUMNS = [
    'operation',
    'desired_revision',
    'processing_operation',
    'state',
    'generation',
    'attempt_count',
    'retry_window_started_at',
    'last_attempt_at',
    'next_retry_at',
    'claim_mode',
    'lease_owner',
    'lease_expires_at',
    'lease_generation',
    'last_successful_sync_at',
    'remote_etag',
    'last_error_code',
    'last_error_summary',
    'changed',
  ];

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $database;

  /**
   * Pure lifecycle.
   *
   * @var \Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncLifecycle
   */
  private $lifecycle;

  /**
   * Constructs the repository.
   */
  public function __construct(Connection $database, BookingSyncLifecycle $lifecycle) {
    $this->database = $database;
    $this->lifecycle = $lifecycle;
  }

  /**
   * Inserts one new operational record without merging a legacy row.
   *
   * This method is not called by current reservation hooks. It exists for the
   * future guarded producer cutover only.
   */
  public function initialize(int $sid, string $submission_uuid, string $environment_namespace, string $operation): array {
    if ($sid <= 0 || trim($submission_uuid) === '' || strlen($submission_uuid) > 128 || !BookingSyncOperation::isValid($operation)) {
      return $this->result(FALSE, FALSE, 'initial_record_invalid');
    }

    try {
      $reference = BookingSyncLifecycle::reservationReference($submission_uuid, $environment_namespace);
      $record = $this->lifecycle->initialRecord(1, $reference, $operation);
      unset($record['id']);

      $legacy_action = [
        BookingSyncOperation::CREATE => 'pending_create',
        BookingSyncOperation::UPDATE => 'pending_update',
        BookingSyncOperation::CANCEL => 'pending_cancel',
      ][$operation];
      $fields = $record + [
        'sid' => $sid,
        'submission_uuid' => $submission_uuid,
        'sync_status' => 'foundation_held',
        'sync_action' => $legacy_action,
        'reservation_value' => '',
        'payload_json' => NULL,
        'last_error' => NULL,
        'last_synced' => NULL,
        'cancelled' => NULL,
      ];

      $id = (int) $this->database->insert(self::TABLE)
        ->fields($fields)
        ->execute();

      return $this->result(TRUE, FALSE, 'inserted', $id, 1, $record['state'], NULL);
    }
    catch (IntegrityConstraintViolationException $e) {
      return $this->result(FALSE, TRUE, 'insert_conflict');
    }
    catch (\Throwable $e) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
  }

  /**
   * Atomically stores a set-once remote identity before any processing claim.
   */
  public function precommitRemoteEventId(int $id, int $expected_generation, string $expected_state, int $expected_attempt_count, string $remote_event_id, bool $verified_reconciliation_binding = FALSE): array {
    $loaded = $this->loadExpectedRecord($id, $expected_generation, $expected_state, $expected_attempt_count);
    if ($loaded['failed']) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
    $record = $loaded['record'];
    if ($record === NULL) {
      return $this->result(FALSE, TRUE, 'cas_conflict');
    }

    return $this->applyIdentityPrecommit($this->lifecycle->precommitRemoteEventId($record, $remote_event_id, $verified_reconciliation_binding));
  }

  /**
   * Atomically finalizes a cancellation proven never to have been dispatched.
   */
  public function finalizeLocalCancellation(int $id, int $expected_generation): array {
    $loaded = $this->loadExpectedRecord($id, $expected_generation, BookingSyncState::CANCEL_PENDING, 0);
    if ($loaded['failed']) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
    $record = $loaded['record'];
    if ($record === NULL) {
      return $this->result(FALSE, TRUE, 'cas_conflict');
    }

    return $this->apply($this->lifecycle->finalizeLocalCancellation($record));
  }

  /**
   * Atomically claims a row matching the caller's expected version and state.
   */
  public function claim(int $id, int $expected_generation, string $expected_state, int $expected_attempt_count, string $worker_identity, bool $explicit_reconciliation_claim = FALSE): array {
    $loaded = $this->loadExpectedRecord($id, $expected_generation, $expected_state, $expected_attempt_count);
    if ($loaded['failed']) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
    $record = $loaded['record'];
    if ($record === NULL) {
      return $this->result(FALSE, TRUE, 'cas_conflict');
    }

    return $this->apply($this->lifecycle->claim($record, $worker_identity, $explicit_reconciliation_claim));
  }

  /**
   * Atomically renews an unexpired lease held by the expected owner/version.
   */
  public function renew(int $id, int $expected_generation, string $expected_state, string $lease_owner, int $expected_lease_expires_at): array {
    $loaded = $this->loadExpectedRecord($id, $expected_generation, $expected_state);
    if ($loaded['failed']) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
    $record = $loaded['record'];
    if ($record === NULL || $record['lease_owner'] !== $lease_owner || (int) $record['lease_expires_at'] !== $expected_lease_expires_at) {
      return $this->result(FALSE, TRUE, 'cas_conflict');
    }

    return $this->apply($this->lifecycle->renew($record, $lease_owner));
  }

  /**
   * Atomically finalizes a successful operation owned by the expected lease.
   */
  public function finalizeSuccess(int $id, int $expected_generation, string $expected_state, string $lease_owner, string $expected_processing_operation, ?string $remote_event_id = NULL, ?string $remote_etag = NULL): array {
    $loaded = $this->loadExpectedRecord($id, $expected_generation, $expected_state);
    if ($loaded['failed']) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
    $record = $loaded['record'];
    if ($record === NULL || $record['lease_owner'] !== $lease_owner || $record['processing_operation'] !== $expected_processing_operation) {
      return $this->result(FALSE, TRUE, 'cas_conflict');
    }

    return $this->apply($this->lifecycle->finalizeSuccess($record, $lease_owner, $remote_event_id, $remote_etag));
  }

  /**
   * Atomically finalizes a classified failure owned by the expected lease.
   */
  public function finalizeFailure(int $id, int $expected_generation, string $expected_state, string $lease_owner, string $expected_processing_operation, string $failure_type, ?int $http_status = NULL, bool $remote_result_possible = FALSE, string $reason = '', ?int $retry_after_seconds = NULL): array {
    $loaded = $this->loadExpectedRecord($id, $expected_generation, $expected_state);
    if ($loaded['failed']) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
    $record = $loaded['record'];
    if ($record === NULL || $record['lease_owner'] !== $lease_owner || $record['processing_operation'] !== $expected_processing_operation) {
      return $this->result(FALSE, TRUE, 'cas_conflict');
    }

    return $this->apply($this->lifecycle->finalizeFailure(
      $record,
      $lease_owner,
      $failure_type,
      $http_status,
      $remote_result_possible,
      $reason,
      $retry_after_seconds
    ));
  }

  /**
   * Atomically converts an expired processing lease to reconciliation.
   */
  public function recoverExpiredLease(int $id, int $expected_generation, string $expected_state, string $expected_lease_owner, int $expected_lease_expires_at, string $recovery_worker_identity): array {
    $loaded = $this->loadExpectedRecord($id, $expected_generation, $expected_state);
    if ($loaded['failed']) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
    $record = $loaded['record'];
    if ($record === NULL || $record['lease_owner'] !== $expected_lease_owner || (int) $record['lease_expires_at'] !== $expected_lease_expires_at) {
      return $this->result(FALSE, TRUE, 'cas_conflict');
    }

    return $this->apply($this->lifecycle->recoverExpiredLease($record, $recovery_worker_identity));
  }

  /**
   * Atomically records a newer desired operation and increments generation.
   */
  public function recordNewDesiredOperation(int $id, int $expected_generation, string $expected_state, string $new_operation, int $new_desired_revision): array {
    $loaded = $this->loadExpectedRecord($id, $expected_generation, $expected_state);
    if ($loaded['failed']) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
    $record = $loaded['record'];
    if ($record === NULL) {
      return $this->result(FALSE, TRUE, 'cas_conflict');
    }

    return $this->apply($this->lifecycle->recordNewDesiredOperation($record, $new_operation, $new_desired_revision));
  }

  /**
   * Loads only a fully initialized operational row matching expected values.
   */
  private function loadExpectedRecord(int $id, int $generation, string $state, ?int $attempt_count = NULL): array {
    if ($id <= 0 || $generation < 1 || !BookingSyncState::isValid($state)) {
      return ['record' => NULL, 'failed' => FALSE];
    }

    try {
      $query = $this->database->select(self::TABLE, 'sync')
        ->fields('sync', self::OPERATIONAL_COLUMNS)
        ->condition('id', $id)
        ->condition('generation', $generation)
        ->condition('state', $state)
        ->condition('state', BookingSyncState::all(), 'IN')
        ->condition('operation', BookingSyncOperation::all(), 'IN');
      if ($attempt_count !== NULL) {
        $query->condition('attempt_count', $attempt_count);
      }

      $row = $query->execute()->fetchAssoc();
      return [
        'record' => is_array($row) ? $this->normalizeRecord($row) : NULL,
        'failed' => FALSE,
      ];
    }
    catch (\Throwable $e) {
      return ['record' => NULL, 'failed' => TRUE];
    }
  }

  /**
   * Applies a pure mutation with atomic expected-value and time predicates.
   */
  private function apply(array $mutation): array {
    return $this->applyWithAllowedChanges($mutation, self::MUTABLE_COLUMNS);
  }

  /**
   * Applies only the dedicated set-once identity mutation shape.
   */
  private function applyIdentityPrecommit(array $mutation): array {
    $required_changes = ['generation', 'google_event_id', 'changed'];
    if (count($mutation['changes'] ?? []) !== count($required_changes)
      || count(array_diff($required_changes, array_keys($mutation['changes'] ?? []))) !== 0
      || !array_key_exists('google_event_id', $mutation['expected'] ?? [])
      || $mutation['expected']['google_event_id'] !== NULL) {
      return $this->result(FALSE, FALSE, 'identity_mutation_invalid');
    }

    return $this->applyWithAllowedChanges($mutation, array_merge(self::MUTABLE_COLUMNS, ['google_event_id']));
  }

  /**
   * Applies one allowlisted pure mutation using an atomic database update.
   */
  private function applyWithAllowedChanges(array $mutation, array $allowed_changes): array {
    if (empty($mutation['success'])) {
      return $this->result(FALSE, FALSE, (string) ($mutation['reason'] ?? 'mutation_rejected'));
    }
    if (!$this->fieldsAreAllowed($mutation['expected'], self::OPERATIONAL_COLUMNS) || !$this->fieldsAreAllowed($mutation['changes'], $allowed_changes)) {
      return $this->result(FALSE, FALSE, 'mutation_fields_invalid');
    }

    try {
      $query = $this->database->update(self::TABLE)
        ->fields($mutation['changes']);
      foreach ($mutation['expected'] as $field => $value) {
        if ($value === NULL) {
          $query->isNull($field);
        }
        else {
          $query->condition($field, $value);
        }
      }
      foreach ($mutation['predicates'] as $predicate) {
        if (!$this->predicateIsAllowed($predicate)) {
          return $this->result(FALSE, FALSE, 'mutation_predicate_invalid');
        }
        $query->condition($predicate['field'], $predicate['value'], $predicate['operator']);
      }

      $affected = (int) $query->execute();
      if ($affected !== 1) {
        return $this->result(FALSE, TRUE, 'cas_conflict');
      }

      $record = $mutation['record'];
      return $this->result(
        TRUE,
        FALSE,
        'applied',
        (int) $record['id'],
        (int) $record['generation'],
        (string) $record['state'],
        $record['lease_expires_at'] === NULL ? NULL : (int) $record['lease_expires_at']
      );
    }
    catch (\Throwable $e) {
      return $this->result(FALSE, FALSE, 'storage_failure');
    }
  }

  /**
   * Converts numeric database strings to strict lifecycle values.
   */
  private function normalizeRecord(array $record): array {
    foreach ([
      'id',
      'generation',
      'desired_revision',
      'attempt_count',
      'first_queued_at',
      'retry_window_started_at',
      'created',
      'changed',
    ] as $field) {
      $record[$field] = (int) $record[$field];
    }
    foreach ([
      'last_attempt_at',
      'next_retry_at',
      'lease_expires_at',
      'lease_generation',
      'last_successful_sync_at',
    ] as $field) {
      $record[$field] = $record[$field] === NULL ? NULL : (int) $record[$field];
    }

    return $record;
  }

  /**
   * Checks all mutation fields against a fixed allowlist.
   */
  private function fieldsAreAllowed(array $fields, array $allowed): bool {
    return count(array_diff(array_keys($fields), $allowed)) === 0;
  }

  /**
   * Allows only the fixed lease-time predicates emitted by the lifecycle.
   */
  private function predicateIsAllowed(array $predicate): bool {
    $field = $predicate['field'] ?? NULL;
    $operator = $predicate['operator'] ?? NULL;

    return in_array($field, ['lease_expires_at', 'next_retry_at'], TRUE)
      && in_array($operator, ['>', '<='], TRUE)
      && !($field === 'next_retry_at' && $operator !== '<=')
      && is_int($predicate['value'] ?? NULL);
  }

  /**
   * Builds a non-sensitive repository result.
   */
  private function result(bool $success, bool $conflict, string $reason, ?int $id = NULL, ?int $generation = NULL, ?string $state = NULL, ?int $lease_expires_at = NULL): array {
    return [
      'success' => $success,
      'conflict' => $conflict,
      'reason' => $reason,
      'record_id' => $id,
      'generation' => $generation,
      'state' => $state,
      'lease_expires_at' => $lease_expires_at,
    ];
  }

}
