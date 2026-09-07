<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Pure state lifecycle used before an atomic database compare-and-swap.
 */
final class BookingSyncLifecycle {

  public const CLAIM_MODE_WORK = 'work';
  public const CLAIM_MODE_RECONCILIATION = 'reconciliation';
  public const DEFAULT_LEASE_SECONDS = 120;

  /**
   * Clock.
   *
   * @var \Drupal\unisonges_structure\GoogleCalendar\State\ClockInterface
   */
  private $clock;

  /**
   * Transition policy.
   *
   * @var \Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncTransitionPolicy
   */
  private $transitionPolicy;

  /**
   * Retry policy.
   *
   * @var \Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncRetryPolicy
   */
  private $retryPolicy;

  /**
   * Failure classifier.
   *
   * @var \Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncFailureClassifier
   */
  private $failureClassifier;

  /**
   * Lease duration in seconds.
   *
   * @var int
   */
  private $leaseSeconds;

  /**
   * Constructs the lifecycle.
   */
  public function __construct(ClockInterface $clock, BookingSyncTransitionPolicy $transition_policy, BookingSyncRetryPolicy $retry_policy, BookingSyncFailureClassifier $failure_classifier, int $lease_seconds = self::DEFAULT_LEASE_SECONDS) {
    if ($lease_seconds < 30 || $lease_seconds > 900) {
      throw new \InvalidArgumentException('Lease duration is outside the safe service range.');
    }

    $this->clock = $clock;
    $this->transitionPolicy = $transition_policy;
    $this->retryPolicy = $retry_policy;
    $this->failureClassifier = $failure_classifier;
    $this->leaseSeconds = $lease_seconds;
  }

  /**
   * Returns a stable opaque reference without exposing a submission UUID.
   */
  public static function reservationReference(string $submission_uuid, string $environment_namespace): string {
    $submission_uuid = trim($submission_uuid);
    $environment_namespace = trim($environment_namespace);
    if ($submission_uuid === '' || strlen($submission_uuid) > 128 || preg_match('/^[a-z0-9_-]{8,64}$/', $environment_namespace) !== 1) {
      throw new \InvalidArgumentException('A bounded submission identity is required.');
    }

    return 'reservation_' . hash('sha256', 'unisonges:gcal:reservation:v1:' . $environment_namespace . ':' . $submission_uuid);
  }

  /**
   * Builds an initial held record for insertion by the repository.
   */
  public function initialRecord(int $id, string $reservation_reference, string $operation, ?int $created_at = NULL): array {
    if ($id <= 0 || !$this->isReservationReferenceValid($reservation_reference) || !BookingSyncOperation::isValid($operation)) {
      throw new \InvalidArgumentException('Invalid initial booking sync record.');
    }

    $created_at = $created_at ?? $this->clock->now();
    if ($created_at <= 0) {
      throw new \InvalidArgumentException('Initial timestamps must be positive.');
    }

    return [
      'id' => $id,
      'reservation_ref' => $reservation_reference,
      'operation' => $operation,
      'desired_revision' => 1,
      'processing_operation' => NULL,
      'state' => $operation === BookingSyncOperation::CANCEL ? BookingSyncState::CANCEL_PENDING : BookingSyncState::QUEUED,
      'generation' => 1,
      'attempt_count' => 0,
      'first_queued_at' => $created_at,
      'retry_window_started_at' => $created_at,
      'last_attempt_at' => NULL,
      'next_retry_at' => NULL,
      'claim_mode' => NULL,
      'lease_owner' => NULL,
      'lease_expires_at' => NULL,
      'lease_generation' => NULL,
      'last_successful_sync_at' => NULL,
      'google_event_id' => NULL,
      'remote_etag' => NULL,
      'last_error_code' => NULL,
      'last_error_summary' => NULL,
      'created' => $created_at,
      'changed' => $created_at,
    ];
  }

  /**
   * Atomically precommits a deterministic remote identity before any claim.
   *
   * Identity generation and secret-key access are intentionally outside this
   * release. This pure mutation only enforces set-once persistence.
   */
  public function precommitRemoteEventId(array $record, string $remote_event_id, bool $verified_reconciliation_binding = FALSE): array {
    $invalid = $this->validateRecord($record);
    if ($invalid !== NULL) {
      return $this->rejected($invalid);
    }
    if (!$this->isPrecommittableRemoteEventId($remote_event_id)) {
      return $this->rejected('remote_event_id_invalid');
    }
    if ($record['google_event_id'] !== NULL) {
      return $this->rejected('remote_event_id_already_committed');
    }
    $reconciliation_lineage = $record['state'] === BookingSyncState::RECONCILIATION_REQUIRED
      || ($record['state'] === BookingSyncState::RETRYABLE_FAILURE
        && $record['claim_mode'] === self::CLAIM_MODE_RECONCILIATION);
    if ($reconciliation_lineage && !$verified_reconciliation_binding) {
      return $this->rejected('reconciliation_identity_verification_required');
    }
    $ordinary_preparation = in_array($record['state'], [BookingSyncState::QUEUED, BookingSyncState::CANCEL_PENDING], TRUE)
      && (int) $record['attempt_count'] === 0;
    if ((!$ordinary_preparation && !$reconciliation_lineage) || $record['lease_owner'] !== NULL) {
      return $this->rejected('identity_precommit_ineligible');
    }

    $effective_now = $this->monotonicNow($record, $this->clock->now());
    return $this->mutation($record, [
      'id' => (int) $record['id'],
      'generation' => (int) $record['generation'],
      'state' => (string) $record['state'],
      'operation' => (string) $record['operation'],
      'desired_revision' => (int) $record['desired_revision'],
      'attempt_count' => (int) $record['attempt_count'],
      'next_retry_at' => $record['next_retry_at'],
      'claim_mode' => $record['claim_mode'],
      'lease_owner' => NULL,
      'google_event_id' => NULL,
      'changed' => (int) $record['changed'],
    ], [
      'generation' => (int) $record['generation'] + 1,
      'google_event_id' => $remote_event_id,
      'changed' => $effective_now,
    ]);
  }

  /**
   * Creates a two-minute work or explicit reconciliation claim mutation.
   */
  public function claim(array $record, string $worker_identity, bool $explicit_reconciliation_claim = FALSE): array {
    $invalid = $this->validateRecord($record);
    if ($invalid !== NULL) {
      return $this->rejected($invalid);
    }
    if (!WorkerIdentity::isValid($worker_identity)) {
      return $this->rejected('worker_identity_invalid');
    }
    $now = $this->clock->now();
    if ($this->clockMovedBackward($record, $now)) {
      return $this->rejected('clock_rollback_detected');
    }
    $state = (string) $record['state'];
    $operation = (string) $record['operation'];
    if ($record['lease_expires_at'] !== NULL && (int) $record['lease_expires_at'] > $now) {
      return $this->rejected('lease_active');
    }
    if ($state === BookingSyncState::RETRYABLE_FAILURE) {
      if ((int) $record['attempt_count'] >= BookingSyncRetryPolicy::MAX_ATTEMPTS) {
        return $this->retryExhaustionMutation($record, $now);
      }
      if ($now >= (int) $record['retry_window_started_at'] + BookingSyncRetryPolicy::MAX_WINDOW_SECONDS) {
        return $this->retryExhaustionMutation($record, $now);
      }
    }
    $no_id_reconciliation_lineage = $state === BookingSyncState::RECONCILIATION_REQUIRED
      || ($state === BookingSyncState::RETRYABLE_FAILURE
        && $record['claim_mode'] === self::CLAIM_MODE_RECONCILIATION);
    if (!$this->isRemoteEventIdValid($record['google_event_id']) && !$no_id_reconciliation_lineage) {
      return $this->rejected('remote_event_id_missing');
    }
    $retry_due = $state !== BookingSyncState::RETRYABLE_FAILURE
      || ($record['next_retry_at'] !== NULL && (int) $record['next_retry_at'] <= $now);
    $reconciliation_retry = $state === BookingSyncState::RETRYABLE_FAILURE
      && ($record['claim_mode'] ?? NULL) === self::CLAIM_MODE_RECONCILIATION;

    $event = $state === BookingSyncState::RECONCILIATION_REQUIRED
      ? BookingSyncTransitionPolicy::EVENT_RECONCILIATION_CLAIM
      : BookingSyncTransitionPolicy::EVENT_CLAIM;
    $decision = $this->transitionPolicy->evaluate($state, BookingSyncState::PROCESSING, $operation, $event, [
      'retry_due' => $retry_due,
      'reconciliation_retry' => $reconciliation_retry,
      'explicit_reconciliation_claim' => $explicit_reconciliation_claim,
    ]);
    if (!$decision['allowed']) {
      return $this->rejected($decision['reason']);
    }

    $effective_now = $this->monotonicNow($record, $now);
    $claim_mode = ($state === BookingSyncState::RECONCILIATION_REQUIRED || $reconciliation_retry)
      ? self::CLAIM_MODE_RECONCILIATION
      : self::CLAIM_MODE_WORK;

    $predicates = [];
    if ($record['next_retry_at'] !== NULL) {
      $predicates[] = ['field' => 'next_retry_at', 'operator' => '<=', 'value' => $now];
    }
    if ($record['lease_expires_at'] !== NULL) {
      $predicates[] = ['field' => 'lease_expires_at', 'operator' => '<=', 'value' => $now];
    }

    return $this->mutation($record, [
      'id' => (int) $record['id'],
      'generation' => (int) $record['generation'],
      'state' => $state,
      'operation' => $operation,
      'desired_revision' => (int) $record['desired_revision'],
      'attempt_count' => (int) $record['attempt_count'],
      'retry_window_started_at' => (int) $record['retry_window_started_at'],
      'next_retry_at' => $record['next_retry_at'],
      'claim_mode' => $record['claim_mode'],
      'lease_owner' => $record['lease_owner'],
      'lease_expires_at' => $record['lease_expires_at'],
      'lease_generation' => $record['lease_generation'],
      'google_event_id' => $record['google_event_id'],
      'remote_etag' => $record['remote_etag'],
      'changed' => (int) $record['changed'],
    ], [
      'generation' => (int) $record['generation'] + 1,
      'state' => BookingSyncState::PROCESSING,
      'processing_operation' => $operation,
      'attempt_count' => (int) $record['attempt_count'] + 1,
      'last_attempt_at' => $effective_now,
      'next_retry_at' => NULL,
      'claim_mode' => $claim_mode,
      'lease_owner' => $worker_identity,
      'lease_expires_at' => $effective_now + $this->leaseSeconds,
      'lease_generation' => (int) $record['generation'] + 1,
      'changed' => $effective_now,
    ], $predicates);
  }

  /**
   * Cancels locally only when set-once identity and attempt history prove no dispatch.
   */
  public function finalizeLocalCancellation(array $record): array {
    $invalid = $this->validateRecord($record);
    if ($invalid !== NULL) {
      return $this->rejected($invalid);
    }

    $decision = $this->transitionPolicy->evaluate(
      (string) $record['state'],
      BookingSyncState::CANCELLED,
      (string) $record['operation'],
      BookingSyncTransitionPolicy::EVENT_LOCAL_CANCEL,
      [
        'no_remote_identity' => $record['google_event_id'] === NULL,
        'never_claimed' => (int) $record['attempt_count'] === 0,
      ]
    );
    if (!$decision['allowed'] || $record['lease_owner'] !== NULL) {
      return $this->rejected($decision['allowed'] ? 'lease_active' : $decision['reason']);
    }

    $effective_now = $this->monotonicNow($record, $this->clock->now());
    return $this->mutation($record, [
      'id' => (int) $record['id'],
      'generation' => (int) $record['generation'],
      'state' => BookingSyncState::CANCEL_PENDING,
      'operation' => BookingSyncOperation::CANCEL,
      'desired_revision' => (int) $record['desired_revision'],
      'attempt_count' => 0,
      'google_event_id' => NULL,
      'lease_owner' => NULL,
      'changed' => (int) $record['changed'],
    ], [
      'generation' => (int) $record['generation'] + 1,
      'state' => BookingSyncState::CANCELLED,
      'next_retry_at' => NULL,
      'claim_mode' => NULL,
      'last_error_code' => NULL,
      'last_error_summary' => NULL,
      'changed' => $effective_now,
    ]);
  }

  /**
   * Renews a still-current, unexpired lease without changing generation.
   */
  public function renew(array $record, string $worker_identity): array {
    $invalid = $this->validateProcessingRecord($record, $worker_identity);
    if ($invalid !== NULL) {
      return $this->rejected($invalid);
    }

    $now = $this->clock->now();
    if ($this->clockMovedBackward($record, $now)) {
      return $this->rejected('clock_rollback_detected');
    }
    if ((int) $record['lease_expires_at'] <= $now) {
      return $this->expiredOwnedLeaseMutation($record, $worker_identity, $now);
    }

    $effective_now = $this->monotonicNow($record, $now);
    $new_expiry = max((int) $record['lease_expires_at'] + 1, $effective_now + $this->leaseSeconds);

    return $this->mutation($record, $this->processingExpectations($record, $worker_identity), [
      'lease_expires_at' => $new_expiry,
      'changed' => $effective_now,
    ], [
      ['field' => 'lease_expires_at', 'operator' => '>', 'value' => $now],
    ]);
  }

  /**
   * Finalizes a successful create, update, or cancel using the lease snapshot.
   */
  public function finalizeSuccess(array $record, string $worker_identity, ?string $remote_event_id = NULL, ?string $remote_etag = NULL): array {
    $invalid = $this->validateProcessingRecord($record, $worker_identity);
    if ($invalid !== NULL) {
      return $this->rejected($invalid);
    }

    $now = $this->clock->now();
    if ($this->clockMovedBackward($record, $now)) {
      return $this->rejected('clock_rollback_detected');
    }
    if ((int) $record['lease_expires_at'] <= $now) {
      return $this->expiredOwnedLeaseMutation($record, $worker_identity, $now);
    }

    $operation = (string) $record['processing_operation'];
    $target_state = $operation === BookingSyncOperation::CANCEL
      ? BookingSyncState::CANCELLED
      : BookingSyncState::SYNCED;
    $decision = $this->transitionPolicy->evaluate(
      BookingSyncState::PROCESSING,
      $target_state,
      $operation,
      BookingSyncTransitionPolicy::EVENT_SUCCESS
    );
    if (!$decision['allowed']) {
      return $this->rejected($decision['reason']);
    }

    $stored_event_id = $record['google_event_id'];
    if (!$this->isRemoteEventIdValid($stored_event_id)) {
      return $this->rejected('remote_event_id_missing');
    }
    if ($operation !== BookingSyncOperation::CANCEL) {
      if (!$this->isRemoteEventIdValid($remote_event_id)) {
        return $this->rejected('remote_event_id_missing');
      }
      if (!hash_equals((string) $stored_event_id, (string) $remote_event_id)) {
        return $this->rejected('remote_event_id_conflict');
      }
    }
    elseif ($remote_event_id !== NULL && (!is_string($stored_event_id) || !hash_equals($stored_event_id, $remote_event_id))) {
      return $this->rejected('remote_event_id_conflict');
    }
    if ($remote_etag !== NULL && !$this->isRemoteEtagValid($remote_etag)) {
      return $this->rejected('remote_etag_invalid');
    }

    $effective_now = $this->monotonicNow($record, $now);
    $changes = [
      'generation' => (int) $record['generation'] + 1,
      'state' => $target_state,
      'processing_operation' => NULL,
      'next_retry_at' => NULL,
      'claim_mode' => NULL,
      'lease_owner' => NULL,
      'lease_expires_at' => NULL,
      'lease_generation' => NULL,
      'last_successful_sync_at' => $effective_now,
      'last_error_code' => NULL,
      'last_error_summary' => NULL,
      'changed' => $effective_now,
    ];
    $changes['remote_etag'] = $target_state === BookingSyncState::CANCELLED
      ? NULL
      : $remote_etag;

    return $this->mutation($record, $this->processingExpectations($record, $worker_identity), $changes, [
      ['field' => 'lease_expires_at', 'operator' => '>', 'value' => $now],
    ]);
  }

  /**
   * Finalizes an allowlisted failure and schedules a bounded retry when safe.
   */
  public function finalizeFailure(array $record, string $worker_identity, string $failure_type, ?int $http_status = NULL, bool $remote_result_possible = FALSE, string $reason = '', ?int $retry_after_seconds = NULL): array {
    $invalid = $this->validateProcessingRecord($record, $worker_identity);
    if ($invalid !== NULL) {
      return $this->rejected($invalid);
    }

    $now = $this->clock->now();
    if ($this->clockMovedBackward($record, $now)) {
      return $this->rejected('clock_rollback_detected');
    }
    if ((int) $record['lease_expires_at'] <= $now) {
      return $this->expiredOwnedLeaseMutation($record, $worker_identity, $now);
    }

    $classification = $this->failureClassifier->classify($failure_type, $http_status, $remote_result_possible, $reason);
    $target_state = $classification['state'];
    $next_retry_at = NULL;

    if ($target_state === BookingSyncState::RETRYABLE_FAILURE) {
      $retry = $this->retryPolicy->schedule(
        (int) $record['attempt_count'],
        (int) $record['retry_window_started_at'],
        $this->monotonicNow($record, $now),
        (string) $record['reservation_ref'] . ':' . (int) $record['generation'],
        $retry_after_seconds
      );
      if (!$retry['retryable']) {
        $target_state = BookingSyncState::PERMANENT_FAILURE;
        $classification = $this->fixedFailure($target_state, 'retry_exhausted');
      }
      else {
        $next_retry_at = $retry['retry_at'];
      }
    }

    $event = $target_state === BookingSyncState::RETRYABLE_FAILURE
      ? BookingSyncTransitionPolicy::EVENT_RETRYABLE_FAILURE
      : ($target_state === BookingSyncState::RECONCILIATION_REQUIRED
        ? BookingSyncTransitionPolicy::EVENT_AMBIGUOUS_FAILURE
        : BookingSyncTransitionPolicy::EVENT_PERMANENT_FAILURE);
    $operation = (string) $record['processing_operation'];
    $decision = $this->transitionPolicy->evaluate(BookingSyncState::PROCESSING, $target_state, $operation, $event);
    if (!$decision['allowed']) {
      return $this->rejected($decision['reason']);
    }

    $effective_now = $this->monotonicNow($record, $now);
    $changes = [
      'generation' => (int) $record['generation'] + 1,
      'state' => $target_state,
      'processing_operation' => NULL,
      'next_retry_at' => $next_retry_at,
      'lease_owner' => NULL,
      'lease_expires_at' => NULL,
      'lease_generation' => NULL,
      'last_error_code' => $classification['error_code'],
      'last_error_summary' => $classification['error_summary'],
      'changed' => $effective_now,
    ];
    if ($target_state !== BookingSyncState::RETRYABLE_FAILURE) {
      $changes['claim_mode'] = NULL;
    }

    return $this->mutation($record, $this->processingExpectations($record, $worker_identity), $changes, [
      ['field' => 'lease_expires_at', 'operator' => '>', 'value' => $now],
    ]);
  }

  /**
   * Recovers an expired lease conservatively as an ambiguous result.
   */
  public function recoverExpiredLease(array $record, string $recovery_worker_identity): array {
    $invalid = $this->validateRecoverableProcessingRecord($record, $recovery_worker_identity);
    if ($invalid !== NULL) {
      return $this->rejected($invalid);
    }

    $now = $this->clock->now();
    $expired = (int) $record['lease_expires_at'] <= $now;
    $decision = $this->transitionPolicy->evaluate(
      BookingSyncState::PROCESSING,
      BookingSyncState::RECONCILIATION_REQUIRED,
      (string) $record['processing_operation'],
      BookingSyncTransitionPolicy::EVENT_LEASE_EXPIRED,
      ['lease_expired' => $expired]
    );
    if (!$decision['allowed']) {
      return $this->rejected($decision['reason']);
    }

    $error = RedactedError::normalize('lease_expired_ambiguous');
    $effective_now = $this->monotonicNow($record, $now);

    return $this->mutation($record, $this->recoveryExpectations($record), [
      'generation' => (int) $record['generation'] + 1,
      'state' => BookingSyncState::RECONCILIATION_REQUIRED,
      'processing_operation' => NULL,
      'next_retry_at' => NULL,
      'claim_mode' => NULL,
      'lease_owner' => NULL,
      'lease_expires_at' => NULL,
      'lease_generation' => NULL,
      'last_error_code' => $error['code'],
      'last_error_summary' => $error['summary'],
      'changed' => $effective_now,
    ], [
      ['field' => 'lease_expires_at', 'operator' => '<=', 'value' => $now],
    ]);
  }

  /**
   * Records a strictly newer desired operation without stealing a lease.
   */
  public function recordNewDesiredOperation(array $record, string $new_operation, int $new_desired_revision): array {
    $invalid = $this->validateRecord($record);
    if ($invalid !== NULL) {
      return $this->rejected($invalid);
    }
    if (!BookingSyncOperation::isValid($new_operation)) {
      return $this->rejected('invalid_operation');
    }
    if ($new_desired_revision <= (int) $record['desired_revision']) {
      return $this->rejected('desired_revision_not_newer');
    }

    $from = (string) $record['state'];
    $effective_operation = $this->coalescedOperation($record, $new_operation);
    $to = $this->stateForNewDesiredOperation($from, $effective_operation);
    if ($to === NULL) {
      return $this->rejected('newer_operation_transition_invalid');
    }

    $decision = $this->transitionPolicy->evaluate(
      $from,
      $to,
      $effective_operation,
      BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION,
      ['newer_desired_generation' => TRUE]
    );
    if (!$decision['allowed']) {
      return $this->rejected($decision['reason']);
    }

    $now = $this->monotonicNow($record, $this->clock->now());
    $expected = [
      'id' => (int) $record['id'],
      'generation' => (int) $record['generation'],
      'state' => $from,
      'operation' => (string) $record['operation'],
      'desired_revision' => (int) $record['desired_revision'],
      'processing_operation' => $record['processing_operation'],
      'attempt_count' => (int) $record['attempt_count'],
      'next_retry_at' => $record['next_retry_at'],
      'claim_mode' => $record['claim_mode'],
      'lease_owner' => $record['lease_owner'],
      'lease_expires_at' => $record['lease_expires_at'],
      'lease_generation' => $record['lease_generation'],
      'google_event_id' => $record['google_event_id'],
      'remote_etag' => $record['remote_etag'],
      'changed' => (int) $record['changed'],
    ];
    $changes = [
      'generation' => (int) $record['generation'] + 1,
      'state' => $to,
      'operation' => $effective_operation,
      'desired_revision' => $new_desired_revision,
      'changed' => $now,
    ];

    if (!in_array($from, [BookingSyncState::PROCESSING, BookingSyncState::PERMANENT_FAILURE, BookingSyncState::RECONCILIATION_REQUIRED], TRUE)) {
      $changes += [
        'processing_operation' => NULL,
        'attempt_count' => 0,
        'retry_window_started_at' => $now,
        'last_attempt_at' => NULL,
        'next_retry_at' => NULL,
        'claim_mode' => NULL,
        'lease_owner' => NULL,
        'lease_expires_at' => NULL,
        'lease_generation' => NULL,
        'last_error_code' => NULL,
        'last_error_summary' => NULL,
      ];
    }

    return $this->mutation($record, $expected, $changes);
  }

  /**
   * Returns expected fields shared by renewal/finalization/recovery CAS.
   */
  private function processingExpectations(array $record, string $worker_identity): array {
    return [
      'id' => (int) $record['id'],
      'generation' => (int) $record['generation'],
      'state' => BookingSyncState::PROCESSING,
      'operation' => (string) $record['operation'],
      'desired_revision' => (int) $record['desired_revision'],
      'processing_operation' => (string) $record['processing_operation'],
      'attempt_count' => (int) $record['attempt_count'],
      'claim_mode' => $record['claim_mode'],
      'lease_owner' => $worker_identity,
      'lease_expires_at' => (int) $record['lease_expires_at'],
      'lease_generation' => (int) $record['lease_generation'],
      'google_event_id' => $record['google_event_id'],
      'remote_etag' => $record['remote_etag'],
      'changed' => (int) $record['changed'],
    ];
  }

  /**
   * Returns expected fields for recovery by a worker other than the dead owner.
   */
  private function recoveryExpectations(array $record): array {
    return [
      'id' => (int) $record['id'],
      'generation' => (int) $record['generation'],
      'state' => BookingSyncState::PROCESSING,
      'operation' => (string) $record['operation'],
      'desired_revision' => (int) $record['desired_revision'],
      'processing_operation' => (string) $record['processing_operation'],
      'attempt_count' => (int) $record['attempt_count'],
      'claim_mode' => $record['claim_mode'],
      'lease_owner' => (string) $record['lease_owner'],
      'lease_expires_at' => (int) $record['lease_expires_at'],
      'lease_generation' => (int) $record['lease_generation'],
      'google_event_id' => $record['google_event_id'],
      'remote_etag' => $record['remote_etag'],
      'changed' => (int) $record['changed'],
    ];
  }

  /**
   * Validates the fields needed by all lifecycle operations.
   */
  private function validateRecord(array $record): ?string {
    $required = [
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
    foreach ($required as $field) {
      if (!array_key_exists($field, $record)) {
        return 'record_field_missing';
      }
    }

    if ((int) $record['id'] <= 0 || (int) $record['generation'] < 1 || (int) $record['desired_revision'] < 1 || (int) $record['attempt_count'] < 0) {
      return 'record_version_invalid';
    }
    if (!$this->isReservationReferenceValid((string) $record['reservation_ref'])) {
      return 'reservation_reference_invalid';
    }
    if (!BookingSyncState::isValid((string) $record['state'])) {
      return 'invalid_state';
    }
    if (!BookingSyncOperation::isValid((string) $record['operation'])) {
      return 'invalid_operation';
    }
    if ((int) $record['first_queued_at'] <= 0 || (int) $record['retry_window_started_at'] <= 0 || (int) $record['created'] <= 0 || (int) $record['changed'] <= 0) {
      return 'record_timestamp_invalid';
    }
    if ($record['last_error_code'] === NULL xor $record['last_error_summary'] === NULL) {
      return 'redacted_error_invalid';
    }
    if ($record['last_error_code'] !== NULL) {
      $error = RedactedError::normalize((string) $record['last_error_code']);
      if ($error['code'] !== $record['last_error_code'] || $error['summary'] !== $record['last_error_summary']) {
        return 'redacted_error_invalid';
      }
    }

    return NULL;
  }

  /**
   * Validates processing ownership and snapshot data.
   */
  private function validateProcessingRecord(array $record, string $worker_identity): ?string {
    $invalid = $this->validateRecord($record);
    if ($invalid !== NULL) {
      return $invalid;
    }
    if (!WorkerIdentity::isValid($worker_identity)) {
      return 'worker_identity_invalid';
    }
    if ($record['state'] !== BookingSyncState::PROCESSING) {
      return 'state_conflict';
    }
    if ($record['lease_owner'] !== $worker_identity) {
      return 'lease_owner_conflict';
    }
    if ($record['lease_expires_at'] === NULL) {
      return 'lease_missing';
    }
    if ($record['lease_generation'] === NULL || (int) $record['lease_generation'] !== (int) $record['generation']) {
      return 'lease_generation_conflict';
    }
    if (!BookingSyncOperation::isValid((string) $record['processing_operation'])) {
      return 'processing_operation_invalid';
    }
    if (!in_array($record['claim_mode'], [self::CLAIM_MODE_WORK, self::CLAIM_MODE_RECONCILIATION], TRUE)) {
      return 'claim_mode_invalid';
    }

    return NULL;
  }

  /**
   * Validates an expired lease for recovery by a distinct opaque worker.
   */
  private function validateRecoverableProcessingRecord(array $record, string $recovery_worker_identity): ?string {
    $invalid = $this->validateRecord($record);
    if ($invalid !== NULL) {
      return $invalid;
    }
    if (!WorkerIdentity::isValid($recovery_worker_identity)) {
      return 'worker_identity_invalid';
    }
    if ($record['state'] !== BookingSyncState::PROCESSING) {
      return 'state_conflict';
    }
    if (!is_string($record['lease_owner']) || !WorkerIdentity::isValid($record['lease_owner'])) {
      return 'lease_owner_invalid';
    }
    if ($record['lease_owner'] === $recovery_worker_identity) {
      return 'recovery_worker_must_differ';
    }
    if ($record['lease_expires_at'] === NULL || $record['lease_generation'] === NULL) {
      return 'lease_missing';
    }
    if (!BookingSyncOperation::isValid((string) $record['processing_operation'])) {
      return 'processing_operation_invalid';
    }
    if (!in_array($record['claim_mode'], [self::CLAIM_MODE_WORK, self::CLAIM_MODE_RECONCILIATION], TRUE)) {
      return 'claim_mode_invalid';
    }

    return NULL;
  }

  /**
   * Chooses the safe state for a newer desired operation.
   */
  private function stateForNewDesiredOperation(string $from, string $operation): ?string {
    if (in_array($from, [BookingSyncState::PROCESSING, BookingSyncState::PERMANENT_FAILURE, BookingSyncState::RECONCILIATION_REQUIRED], TRUE)) {
      return $from;
    }
    if ($from === BookingSyncState::SYNCED) {
      return $operation === BookingSyncOperation::CANCEL
        ? BookingSyncState::CANCEL_PENDING
        : ($operation === BookingSyncOperation::UPDATE ? BookingSyncState::QUEUED : NULL);
    }
    if ($from === BookingSyncState::CANCELLED) {
      // A deleted event ID is an immutable prior incarnation. Re-creation must
      // reconcile rather than silently reuse or overwrite it.
      return $operation === BookingSyncOperation::CREATE
        ? BookingSyncState::RECONCILIATION_REQUIRED
        : NULL;
    }
    if ($from === BookingSyncState::QUEUED) {
      return $operation === BookingSyncOperation::CANCEL ? BookingSyncState::CANCEL_PENDING : BookingSyncState::QUEUED;
    }
    if ($from === BookingSyncState::CANCEL_PENDING) {
      if ($operation === BookingSyncOperation::CANCEL) {
        return BookingSyncState::CANCEL_PENDING;
      }
      return $operation === BookingSyncOperation::UPDATE ? BookingSyncState::QUEUED : NULL;
    }
    if ($from === BookingSyncState::RETRYABLE_FAILURE) {
      return $operation === BookingSyncOperation::CANCEL ? BookingSyncState::CANCEL_PENDING : BookingSyncState::QUEUED;
    }

    return NULL;
  }

  /**
   * Preserves CREATE/UPDATE remote-existence intent while coalescing edits.
   */
  private function coalescedOperation(array $record, string $new_operation): string {
    $state = (string) $record['state'];
    $current_operation = (string) $record['operation'];
    $non_cancel = [BookingSyncOperation::CREATE, BookingSyncOperation::UPDATE];
    if (in_array($state, [
      BookingSyncState::QUEUED,
      BookingSyncState::RETRYABLE_FAILURE,
      BookingSyncState::PERMANENT_FAILURE,
      BookingSyncState::RECONCILIATION_REQUIRED,
    ], TRUE)
      && in_array($current_operation, $non_cancel, TRUE)
      && in_array($new_operation, $non_cancel, TRUE)) {
      return $current_operation;
    }

    return $new_operation;
  }

  /**
   * Keeps persisted timestamps monotonic across a local clock rollback.
   */
  private function monotonicNow(array $record, int $now): int {
    return max(
      $now,
      (int) ($record['changed'] ?? 0),
      (int) ($record['last_attempt_at'] ?? 0),
      (int) ($record['created'] ?? 0)
    );
  }

  /**
   * Builds a fixed failure classification.
   */
  private function fixedFailure(string $state, string $code): array {
    $error = RedactedError::normalize($code);
    return [
      'state' => $state,
      'error_code' => $error['code'],
      'error_summary' => $error['summary'],
    ];
  }

  /**
   * Atomically stops a retry row whose attempt or age budget is exhausted.
   */
  private function retryExhaustionMutation(array $record, int $now): array {
    $decision = $this->transitionPolicy->evaluate(
      BookingSyncState::RETRYABLE_FAILURE,
      BookingSyncState::PERMANENT_FAILURE,
      (string) $record['operation'],
      BookingSyncTransitionPolicy::EVENT_RETRY_EXHAUSTED
    );
    if (!$decision['allowed']) {
      return $this->rejected($decision['reason']);
    }

    $error = RedactedError::normalize('retry_exhausted');
    $effective_now = $this->monotonicNow($record, $now);
    return $this->mutation($record, [
      'id' => (int) $record['id'],
      'generation' => (int) $record['generation'],
      'state' => BookingSyncState::RETRYABLE_FAILURE,
      'operation' => (string) $record['operation'],
      'desired_revision' => (int) $record['desired_revision'],
      'attempt_count' => (int) $record['attempt_count'],
      'retry_window_started_at' => (int) $record['retry_window_started_at'],
      'next_retry_at' => $record['next_retry_at'],
      'claim_mode' => $record['claim_mode'],
      'lease_owner' => $record['lease_owner'],
      'lease_expires_at' => $record['lease_expires_at'],
      'lease_generation' => $record['lease_generation'],
      'google_event_id' => $record['google_event_id'],
      'remote_etag' => $record['remote_etag'],
      'changed' => (int) $record['changed'],
    ], [
      'generation' => (int) $record['generation'] + 1,
      'state' => BookingSyncState::PERMANENT_FAILURE,
      'processing_operation' => NULL,
      'next_retry_at' => NULL,
      'claim_mode' => NULL,
      'lease_owner' => NULL,
      'lease_expires_at' => NULL,
      'lease_generation' => NULL,
      'last_error_code' => $error['code'],
      'last_error_summary' => $error['summary'],
      'changed' => $effective_now,
    ]);
  }

  /**
   * Converts an owner-observed timeout to durable reconciliation immediately.
   */
  private function expiredOwnedLeaseMutation(array $record, string $worker_identity, int $now): array {
    $decision = $this->transitionPolicy->evaluate(
      BookingSyncState::PROCESSING,
      BookingSyncState::RECONCILIATION_REQUIRED,
      (string) $record['processing_operation'],
      BookingSyncTransitionPolicy::EVENT_LEASE_EXPIRED,
      ['lease_expired' => TRUE]
    );
    if (!$decision['allowed']) {
      return $this->rejected($decision['reason']);
    }

    $error = RedactedError::normalize('lease_expired_ambiguous');
    $effective_now = $this->monotonicNow($record, $now);
    return $this->mutation($record, $this->processingExpectations($record, $worker_identity), [
      'generation' => (int) $record['generation'] + 1,
      'state' => BookingSyncState::RECONCILIATION_REQUIRED,
      'processing_operation' => NULL,
      'next_retry_at' => NULL,
      'claim_mode' => NULL,
      'lease_owner' => NULL,
      'lease_expires_at' => NULL,
      'lease_generation' => NULL,
      'last_error_code' => $error['code'],
      'last_error_summary' => $error['summary'],
      'changed' => $effective_now,
    ], [
      ['field' => 'lease_expires_at', 'operator' => '<=', 'value' => $now],
    ]);
  }

  /**
   * Detects a wall-clock value older than already persisted local history.
   */
  private function clockMovedBackward(array $record, int $now): bool {
    return $now < max(
      (int) $record['changed'],
      (int) ($record['last_attempt_at'] ?? 0),
      (int) $record['created']
    );
  }

  /**
   * Returns whether an opaque reservation reference is well formed.
   */
  private function isReservationReferenceValid(string $reference): bool {
    return preg_match('/^reservation_[a-f0-9]{64}$/', $reference) === 1;
  }

  /**
   * Returns whether an event ID is safe to retain as opaque metadata.
   */
  private function isRemoteEventIdValid($event_id): bool {
    return is_string($event_id) && preg_match('/^[a-z0-9_-]{5,255}$/', $event_id) === 1;
  }

  /**
   * Checks a new client-selected ID against Calendar's base32hex alphabet.
   */
  private function isPrecommittableRemoteEventId(string $event_id): bool {
    return preg_match('/^[a-v0-9]{5,255}$/', $event_id) === 1;
  }

  /**
   * Returns whether an ETag is bounded printable operational metadata.
   */
  private function isRemoteEtagValid(string $etag): bool {
    return strlen($etag) <= 255 && preg_match('/^[\x20-\x7E]+$/', $etag) === 1;
  }

  /**
   * Builds a pure mutation plan for a repository CAS.
   */
  private function mutation(array $record, array $expected, array $changes, array $predicates = []): array {
    return [
      'success' => TRUE,
      'reason' => 'mutation_ready',
      'expected' => $expected,
      'predicates' => $predicates,
      'changes' => $changes,
      'record' => array_replace($record, $changes),
    ];
  }

  /**
   * Returns a fixed rejection with no mutation fields.
   */
  private function rejected(string $reason): array {
    return [
      'success' => FALSE,
      'reason' => $reason,
      'expected' => [],
      'predicates' => [],
      'changes' => [],
      'record' => NULL,
    ];
  }

}
