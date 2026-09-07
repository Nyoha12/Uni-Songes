<?php

/**
 * @file
 * Deterministic no-Drupal-runtime tests for the Calendar state foundation.
 */

$module_root = dirname(__DIR__, 2);
$state_root = $module_root . '/src/GoogleCalendar/State';

require_once $state_root . '/BookingSyncState.php';
require_once $state_root . '/BookingSyncOperation.php';
require_once $state_root . '/ClockInterface.php';
require_once $state_root . '/WorkerIdentity.php';
require_once $state_root . '/RedactedError.php';
require_once $state_root . '/BookingSyncTransitionPolicy.php';
require_once $state_root . '/BookingSyncRetryPolicy.php';
require_once $state_root . '/BookingSyncFailureClassifier.php';
require_once $state_root . '/BookingSyncLifecycle.php';
require_once $state_root . '/LegacyBookingSyncClassifier.php';
require_once $state_root . '/BookingSyncDiagnosticReader.php';
require_once $module_root . '/src/GoogleCalendar/GoogleCalendarActivationBoundary.php';
require_once $module_root . '/src/GoogleCalendar/GoogleCalendarClientInterface.php';
require_once $module_root . '/src/GoogleCalendar/GoogleCalendarClient.php';
require_once $module_root . '/unisonges_structure.install';

use Drupal\unisonges_structure\GoogleCalendar\GoogleCalendarActivationBoundary;
use Drupal\unisonges_structure\GoogleCalendar\GoogleCalendarClient;
use Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncDiagnosticReader;
use Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncFailureClassifier;
use Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncLifecycle;
use Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncOperation;
use Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncRetryPolicy;
use Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncState;
use Drupal\unisonges_structure\GoogleCalendar\State\BookingSyncTransitionPolicy;
use Drupal\unisonges_structure\GoogleCalendar\State\ClockInterface;
use Drupal\unisonges_structure\GoogleCalendar\State\LegacyBookingSyncClassifier;
use Drupal\unisonges_structure\GoogleCalendar\State\RedactedError;

final class FrozenClock implements ClockInterface {

  private $now;

  public function __construct(int $now) {
    $this->now = $now;
  }

  public function now(): int {
    return $this->now;
  }

  public function set(int $now): void {
    $this->now = $now;
  }

  public function advance(int $seconds): void {
    $this->now += $seconds;
  }

}

/**
 * Minimal deterministic CAS store for pure lifecycle mutation plans.
 */
final class MemoryCasStore {

  private $record;

  public function __construct(array $record) {
    $this->record = $record;
  }

  public function record(): array {
    return $this->record;
  }

  public function apply(array $mutation): bool {
    if (empty($mutation['success'])) {
      return FALSE;
    }
    foreach ($mutation['expected'] as $field => $value) {
      if (!array_key_exists($field, $this->record) || $this->record[$field] !== $value) {
        return FALSE;
      }
    }
    foreach ($mutation['predicates'] as $predicate) {
      $actual = $this->record[$predicate['field']] ?? NULL;
      if ($predicate['operator'] === '>' && !($actual > $predicate['value'])) {
        return FALSE;
      }
      if ($predicate['operator'] === '<=' && !($actual <= $predicate['value'])) {
        return FALSE;
      }
    }

    $this->record = array_replace($this->record, $mutation['changes']);
    return TRUE;
  }

}

$assertions = 0;

function check(bool $condition, string $message): void {
  global $assertions;
  $assertions++;
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function same($expected, $actual, string $message): void {
  check($expected === $actual, $message);
}

function containsNoSensitiveValue(array $value, array $forbidden, string $message): void {
  $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  check(is_string($encoded), $message . ': encoding');
  foreach ($forbidden as $needle) {
    check(strpos($encoded, $needle) === FALSE, $message . ': forbidden value');
  }
}

function functionSource(string $function_name): string {
  $reflection = new ReflectionFunction($function_name);
  $lines = file($reflection->getFileName());
  if (!is_array($lines)) {
    throw new RuntimeException('Unable to read function source.');
  }

  return implode('', array_slice(
    $lines,
    $reflection->getStartLine() - 1,
    $reflection->getEndLine() - $reflection->getStartLine() + 1
  ));
}

function referenceFor(int $id): string {
  return BookingSyncLifecycle::reservationReference('fixture-source-' . $id, 'test_env_01');
}

function eventIdFor(int $id): string {
  return 'a' . str_pad((string) $id, 12, '0', STR_PAD_LEFT);
}

function initialRecord(BookingSyncLifecycle $lifecycle, int $id, string $operation, int $created = 1000): array {
  $record = $lifecycle->initialRecord($id, referenceFor($id), $operation, $created);
  $precommit = $lifecycle->precommitRemoteEventId($record, eventIdFor($id));
  check($precommit['success'], 'Fixture identity precommit must succeed.');
  $store = new MemoryCasStore($record);
  check($store->apply($precommit), 'Fixture identity precommit CAS must apply.');
  return $store->record();
}

function claimedRecord(BookingSyncLifecycle $lifecycle, int $id, string $operation, string $worker, int $attempts_before_claim = 0): array {
  $record = initialRecord($lifecycle, $id, $operation);
  $record['attempt_count'] = $attempts_before_claim;
  $claim = $lifecycle->claim($record, $worker);
  check($claim['success'], 'Fixture claim must succeed.');
  return $claim['record'];
}

$clock = new FrozenClock(1000);
$transitions = new BookingSyncTransitionPolicy();
$retries = new BookingSyncRetryPolicy();
$classifier = new BookingSyncFailureClassifier();
$lifecycle = new BookingSyncLifecycle($clock, $transitions, $retries, $classifier, 120);
$worker_a = 'worker_' . str_repeat('a', 32);
$worker_b = 'worker_' . str_repeat('b', 32);

// Exact public vocabulary and independent desired operations.
same([
  'queued',
  'processing',
  'synced',
  'retryable_failure',
  'permanent_failure',
  'cancel_pending',
  'cancelled',
  'reconciliation_required',
], BookingSyncState::all(), 'State vocabulary must be exact.');
same(['create', 'update', 'cancel'], BookingSyncOperation::all(), 'Operation vocabulary must be exact.');

// Exhaustively compare all state/operation/event combinations to the reviewed
// transition matrix. This covers every allowed and rejected transition.
$events = [
  BookingSyncTransitionPolicy::EVENT_CLAIM,
  BookingSyncTransitionPolicy::EVENT_RECONCILIATION_CLAIM,
  BookingSyncTransitionPolicy::EVENT_SUCCESS,
  BookingSyncTransitionPolicy::EVENT_RETRYABLE_FAILURE,
  BookingSyncTransitionPolicy::EVENT_PERMANENT_FAILURE,
  BookingSyncTransitionPolicy::EVENT_AMBIGUOUS_FAILURE,
  BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION,
  BookingSyncTransitionPolicy::EVENT_RETRY_EXHAUSTED,
  BookingSyncTransitionPolicy::EVENT_LEASE_EXPIRED,
  BookingSyncTransitionPolicy::EVENT_LOCAL_CANCEL,
];
$allowed = [];
$allow = static function (string $from, string $to, string $operation, string $event) use (&$allowed): void {
  $allowed[implode('|', [$from, $to, $operation, $event])] = TRUE;
};
foreach ([BookingSyncOperation::CREATE, BookingSyncOperation::UPDATE] as $operation) {
  $allow(BookingSyncState::QUEUED, BookingSyncState::PROCESSING, $operation, BookingSyncTransitionPolicy::EVENT_CLAIM);
  $allow(BookingSyncState::PROCESSING, BookingSyncState::SYNCED, $operation, BookingSyncTransitionPolicy::EVENT_SUCCESS);
  $allow(BookingSyncState::QUEUED, BookingSyncState::QUEUED, $operation, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
}
$allow(BookingSyncState::CANCEL_PENDING, BookingSyncState::PROCESSING, BookingSyncOperation::CANCEL, BookingSyncTransitionPolicy::EVENT_CLAIM);
$allow(BookingSyncState::PROCESSING, BookingSyncState::CANCELLED, BookingSyncOperation::CANCEL, BookingSyncTransitionPolicy::EVENT_SUCCESS);
$allow(BookingSyncState::CANCEL_PENDING, BookingSyncState::CANCELLED, BookingSyncOperation::CANCEL, BookingSyncTransitionPolicy::EVENT_LOCAL_CANCEL);
$allow(BookingSyncState::CANCEL_PENDING, BookingSyncState::CANCEL_PENDING, BookingSyncOperation::CANCEL, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
foreach (BookingSyncOperation::all() as $operation) {
  $allow(BookingSyncState::RETRYABLE_FAILURE, BookingSyncState::PROCESSING, $operation, BookingSyncTransitionPolicy::EVENT_CLAIM);
  $allow(BookingSyncState::RECONCILIATION_REQUIRED, BookingSyncState::PROCESSING, $operation, BookingSyncTransitionPolicy::EVENT_RECONCILIATION_CLAIM);
  $allow(BookingSyncState::PROCESSING, BookingSyncState::RETRYABLE_FAILURE, $operation, BookingSyncTransitionPolicy::EVENT_RETRYABLE_FAILURE);
  $allow(BookingSyncState::PROCESSING, BookingSyncState::PERMANENT_FAILURE, $operation, BookingSyncTransitionPolicy::EVENT_PERMANENT_FAILURE);
  $allow(BookingSyncState::PROCESSING, BookingSyncState::RECONCILIATION_REQUIRED, $operation, BookingSyncTransitionPolicy::EVENT_AMBIGUOUS_FAILURE);
  $allow(BookingSyncState::RETRYABLE_FAILURE, BookingSyncState::PERMANENT_FAILURE, $operation, BookingSyncTransitionPolicy::EVENT_RETRY_EXHAUSTED);
  $allow(BookingSyncState::PROCESSING, BookingSyncState::RECONCILIATION_REQUIRED, $operation, BookingSyncTransitionPolicy::EVENT_LEASE_EXPIRED);
  foreach ([BookingSyncState::PROCESSING, BookingSyncState::PERMANENT_FAILURE, BookingSyncState::RECONCILIATION_REQUIRED] as $same_state) {
    $allow($same_state, $same_state, $operation, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
  }
}
$allow(BookingSyncState::SYNCED, BookingSyncState::QUEUED, BookingSyncOperation::UPDATE, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
$allow(BookingSyncState::SYNCED, BookingSyncState::CANCEL_PENDING, BookingSyncOperation::CANCEL, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
$allow(BookingSyncState::CANCELLED, BookingSyncState::RECONCILIATION_REQUIRED, BookingSyncOperation::CREATE, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
$allow(BookingSyncState::QUEUED, BookingSyncState::CANCEL_PENDING, BookingSyncOperation::CANCEL, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
$allow(BookingSyncState::CANCEL_PENDING, BookingSyncState::QUEUED, BookingSyncOperation::UPDATE, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
$allow(BookingSyncState::RETRYABLE_FAILURE, BookingSyncState::CANCEL_PENDING, BookingSyncOperation::CANCEL, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
$allow(BookingSyncState::RETRYABLE_FAILURE, BookingSyncState::QUEUED, BookingSyncOperation::CREATE, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);
$allow(BookingSyncState::RETRYABLE_FAILURE, BookingSyncState::QUEUED, BookingSyncOperation::UPDATE, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION);

foreach (BookingSyncState::all() as $from) {
  foreach (BookingSyncState::all() as $to) {
    foreach (BookingSyncOperation::all() as $operation) {
      foreach ($events as $event) {
        $key = implode('|', [$from, $to, $operation, $event]);
        $decision = $transitions->evaluate($from, $to, $operation, $event, [
          'retry_due' => TRUE,
          'explicit_reconciliation_claim' => TRUE,
          'reconciliation_retry' => TRUE,
          'newer_desired_generation' => TRUE,
          'lease_expired' => TRUE,
          'no_remote_identity' => TRUE,
          'never_claimed' => TRUE,
        ]);
        same(isset($allowed[$key]), $decision['allowed'], 'Transition matrix mismatch.');
        check(preg_match('/^[a-z_]+$/', $decision['reason']) === 1, 'Transition reason must be machine-readable.');
      }
    }
  }
}
check(!$transitions->evaluate(BookingSyncState::RETRYABLE_FAILURE, BookingSyncState::PROCESSING, BookingSyncOperation::CREATE, BookingSyncTransitionPolicy::EVENT_CLAIM)['allowed'], 'A retry not yet due must fail closed.');
check(!$transitions->evaluate(BookingSyncState::RECONCILIATION_REQUIRED, BookingSyncState::PROCESSING, BookingSyncOperation::CREATE, BookingSyncTransitionPolicy::EVENT_RECONCILIATION_CLAIM)['allowed'], 'Reconciliation requires an explicit claim.');
check(!$transitions->evaluate(BookingSyncState::SYNCED, BookingSyncState::QUEUED, BookingSyncOperation::UPDATE, BookingSyncTransitionPolicy::EVENT_NEWER_DESIRED_OPERATION)['allowed'], 'A newer desired generation guard is required.');
check(!$transitions->evaluate(BookingSyncState::PROCESSING, BookingSyncState::RECONCILIATION_REQUIRED, BookingSyncOperation::CREATE, BookingSyncTransitionPolicy::EVENT_LEASE_EXPIRED)['allowed'], 'Lease recovery requires proven expiry.');
check(!$transitions->evaluate(BookingSyncState::CANCEL_PENDING, BookingSyncState::CANCELLED, BookingSyncOperation::CANCEL, BookingSyncTransitionPolicy::EVENT_LOCAL_CANCEL)['allowed'], 'Local cancellation requires no-dispatch proof.');

// Initial records, set-once identity precommit, and proved local cancellation.
$unprepared_create = $lifecycle->initialRecord(1, referenceFor(1), BookingSyncOperation::CREATE, 1000);
$create_precommit_a = $lifecycle->precommitRemoteEventId($unprepared_create, eventIdFor(1));
$create_precommit_b = $lifecycle->precommitRemoteEventId($unprepared_create, eventIdFor(1));
check(!$lifecycle->claim($unprepared_create, $worker_a)['success'], 'Ordinary work cannot claim an unprepared identity.');
$identity_store = new MemoryCasStore($unprepared_create);
check($identity_store->apply($create_precommit_a), 'First set-once identity precommit wins.');
check(!$identity_store->apply($create_precommit_b), 'Concurrent identity precommit loses CAS.');
$create_initial = $identity_store->record();
check(!$lifecycle->precommitRemoteEventId($create_initial, eventIdFor(1))['success'], 'A committed identity cannot be overwritten or replayed.');
$update_initial = initialRecord($lifecycle, 2, BookingSyncOperation::UPDATE);
$cancel_initial = $lifecycle->initialRecord(3, referenceFor(3), BookingSyncOperation::CANCEL, 1000);
same(BookingSyncState::QUEUED, $create_initial['state'], 'Create starts queued.');
same(BookingSyncState::QUEUED, $update_initial['state'], 'Update starts queued.');
same(BookingSyncState::CANCEL_PENDING, $cancel_initial['state'], 'Cancel starts cancel-pending.');
same(0, $create_initial['attempt_count'], 'Initial attempt count is zero.');
same(1000, $create_initial['first_queued_at'], 'First queued time is durable.');
same(1, $create_initial['desired_revision'], 'Initial source revision is durable and independent of CAS generation.');
$local_cancel = $lifecycle->finalizeLocalCancellation($cancel_initial);
check($local_cancel['success'], 'A never-claimed cancellation without remote identity finalizes locally.');
$local_cancel_store = new MemoryCasStore($cancel_initial);
check($local_cancel_store->apply($local_cancel), 'Local cancellation proof is committed with CAS.');
same(BookingSyncState::CANCELLED, $local_cancel_store->record()['state'], 'Proved local cancellation reaches cancelled.');
same(NULL, $local_cancel_store->record()['last_successful_sync_at'], 'Local cancellation does not claim a remote sync success.');

// First claim and duplicate-worker race: one snapshot can be applied once.
$claim_a = $lifecycle->claim($create_initial, $worker_a);
$claim_b = $lifecycle->claim($create_initial, $worker_b);
check($claim_a['success'] && $claim_b['success'], 'Both workers may form a plan from the same stale snapshot.');
$race_store = new MemoryCasStore($create_initial);
check($race_store->apply($claim_a), 'The first atomic claim wins.');
check(!$race_store->apply($claim_b), 'The concurrent second claim loses CAS.');
$processing = $race_store->record();
same(BookingSyncState::PROCESSING, $processing['state'], 'Claim enters processing.');
same(3, $processing['generation'], 'Identity precommit and claim each increment generation.');
same(1, $processing['attempt_count'], 'Claim increments attempt count.');
same(1120, $processing['lease_expires_at'], 'Default lease is exactly two minutes.');
same($processing['generation'], $processing['lease_generation'], 'Lease records its claimed generation.');

// Renewal and finalization ownership/CAS.
$wrong_renewal = $lifecycle->renew($processing, $worker_b);
check(!$wrong_renewal['success'] && $wrong_renewal['reason'] === 'lease_owner_conflict', 'Non-owner renewal is rejected.');
$clock->advance(30);
$renewal = $lifecycle->renew($processing, $worker_a);
check($renewal['success'], 'Owner renewal succeeds.');
check($renewal['record']['lease_expires_at'] > $processing['lease_expires_at'], 'Renewal extends the lease.');
$renew_store = new MemoryCasStore($processing);
check($renew_store->apply($renewal), 'Renewal CAS applies.');
$renewed = $renew_store->record();

$wrong_finalize = $lifecycle->finalizeSuccess($renewed, $worker_b, eventIdFor(1), '"etag-a"');
check(!$wrong_finalize['success'], 'Non-owner finalization is rejected.');
$success = $lifecycle->finalizeSuccess($renewed, $worker_a, eventIdFor(1), '"etag-a"');
check($success['success'], 'Owner finalization succeeds.');
$final_store = new MemoryCasStore($renewed);
check($final_store->apply($success), 'Finalization CAS applies once.');
check(!$final_store->apply($success), 'Repeated identical finalization loses CAS.');
same(BookingSyncState::SYNCED, $final_store->record()['state'], 'Create finalizes synced.');
check(!$lifecycle->finalizeSuccess($final_store->record(), $worker_a, eventIdFor(1))['success'], 'Finalizing an already-final row is rejected.');
check(!$lifecycle->finalizeSuccess($renewed, $worker_a, 'different_event', '"etag-a"')['success'], 'A response cannot replace the precommitted event ID.');

// Update and cancel success use their independent operations.
$clock->set(1000);
$update_processing = claimedRecord($lifecycle, 4, BookingSyncOperation::UPDATE, $worker_a);
check($lifecycle->finalizeSuccess($update_processing, $worker_a, eventIdFor(4), '"etag-u"')['success'], 'Update finalizes using the same event ID.');
$update_processing['remote_etag'] = '"etag-old"';
$etag_cleared = $lifecycle->finalizeSuccess($update_processing, $worker_a, eventIdFor(4));
same(NULL, $etag_cleared['record']['remote_etag'], 'Success without a returned ETag clears stale remote version metadata.');
$cancel_processing = claimedRecord($lifecycle, 5, BookingSyncOperation::CANCEL, $worker_a);
$cancel_success = $lifecycle->finalizeSuccess($cancel_processing, $worker_a);
check($cancel_success['success'], 'Cancel finalization succeeds for the owner.');
same(BookingSyncState::CANCELLED, $cancel_success['record']['state'], 'Cancel processing ends cancelled.');

// Explicit reconciliation and retry eligibility.
$reconcile = $lifecycle->initialRecord(6, referenceFor(6), BookingSyncOperation::CREATE, 1000);
$reconcile['state'] = BookingSyncState::RECONCILIATION_REQUIRED;
check(!$lifecycle->claim($reconcile, $worker_a)['success'], 'Ordinary claim cannot take reconciliation state.');
$no_id_reconciliation_claim = $lifecycle->claim($reconcile, $worker_a, TRUE);
check($no_id_reconciliation_claim['success'], 'Explicit reconciliation claim succeeds.');
$no_id_reconciliation_store = new MemoryCasStore($reconcile);
check($no_id_reconciliation_store->apply($no_id_reconciliation_claim), 'A NULL event identity remains NULL-aware in reconciliation CAS.');
check(!$lifecycle->precommitRemoteEventId($reconcile, eventIdFor(6))['success'], 'Reconciliation identity binding requires explicit verification.');
check($lifecycle->precommitRemoteEventId($reconcile, eventIdFor(6), TRUE)['success'], 'Verified reconciliation may bind a set-once identity before a claim.');
$no_id_reconciliation_failure = $lifecycle->finalizeFailure(
  $no_id_reconciliation_store->record(),
  $worker_a,
  BookingSyncFailureClassifier::TYPE_NETWORK
);
check($no_id_reconciliation_failure['success'], 'No-ID reconciliation may persist a pre-dispatch transient failure.');
$no_id_retry_store = new MemoryCasStore($no_id_reconciliation_store->record());
check($no_id_retry_store->apply($no_id_reconciliation_failure), 'No-ID reconciliation retry persists atomically.');
$no_id_retry = $no_id_retry_store->record();
same(BookingSyncLifecycle::CLAIM_MODE_RECONCILIATION, $no_id_retry['claim_mode'], 'No-ID retry retains reconciliation lineage.');
check($lifecycle->precommitRemoteEventId($no_id_retry, eventIdFor(6), TRUE)['success'], 'A transient reconciliation probe retains a verified identity-binding path.');
$clock->set((int) $no_id_retry['next_retry_at'] - 1);
same('retry_not_due', $lifecycle->claim($no_id_retry, $worker_a, TRUE)['reason'], 'No-ID reconciliation retry still obeys due time.');
$clock->set((int) $no_id_retry['next_retry_at']);
same('reconciliation_claim_required', $lifecycle->claim($no_id_retry, $worker_a)['reason'], 'No-ID reconciliation retry requires an explicit claim.');
$no_id_retry_claim = $lifecycle->claim($no_id_retry, $worker_a, TRUE);
check($no_id_retry_claim['success'], 'Due no-ID reconciliation retry can be explicitly claimed.');
check($no_id_retry_store->apply($no_id_retry_claim), 'Due no-ID reconciliation retry applies with NULL-aware CAS.');
$no_id_exhaustion = $no_id_retry;
$no_id_exhaustion['attempt_count'] = BookingSyncRetryPolicy::MAX_ATTEMPTS;
$no_id_terminal = $lifecycle->claim($no_id_exhaustion, $worker_a);
same(BookingSyncState::PERMANENT_FAILURE, $no_id_terminal['record']['state'], 'No-ID reconciliation lineage can terminalize an exhausted retry budget.');
$clock->set(1000);

$retry_record = initialRecord($lifecycle, 7, BookingSyncOperation::CREATE);
$retry_error = RedactedError::normalize('transient_network_failure');
$retry_record['state'] = BookingSyncState::RETRYABLE_FAILURE;
$retry_record['next_retry_at'] = 1060;
$retry_record['claim_mode'] = BookingSyncLifecycle::CLAIM_MODE_WORK;
$retry_record['last_error_code'] = $retry_error['code'];
$retry_record['last_error_summary'] = $retry_error['summary'];
check(!$lifecycle->claim($retry_record, $worker_a)['success'], 'Retry before due time is rejected.');
$clock->set(1060);
check($lifecycle->claim($retry_record, $worker_a)['success'], 'Retry at due time succeeds.');

// Deterministic retry attempts 1-10, 48-hour cap, six-hour delay cap.
for ($attempt = 1; $attempt <= 10; $attempt++) {
  $decision_a = $retries->schedule($attempt, 1000, 1000, 'opaque-mapping-generation');
  $decision_b = $retries->schedule($attempt, 1000, 1000, 'opaque-mapping-generation');
  same($decision_a, $decision_b, 'Retry jitter must be deterministic.');
  same($attempt < 10, $decision_a['retryable'], 'Attempt ceiling mismatch.');
  if ($decision_a['retryable']) {
    $expected_base = min(
      BookingSyncRetryPolicy::MAX_DELAY_SECONDS,
      BookingSyncRetryPolicy::INITIAL_DELAY_SECONDS * (2 ** ($attempt - 1))
    );
    same($expected_base, $decision_a['base_delay'], 'Retry base delay must be exponential.');
    check($decision_a['jitter'] >= 0 && $decision_a['jitter'] <= min(BookingSyncRetryPolicy::MAX_JITTER_SECONDS, intdiv($expected_base, 4)), 'Retry jitter is outside its deterministic bound.');
    check($decision_a['delay'] >= $expected_base, 'Retry jitter cannot shorten exponential backoff.');
    check($decision_a['delay'] <= BookingSyncRetryPolicy::MAX_DELAY_SECONDS, 'Retry delay exceeds six hours.');
    check($decision_a['retry_at'] < 1000 + BookingSyncRetryPolicy::MAX_WINDOW_SECONDS, 'Retry exceeds 48-hour window.');
  }
}
$retry_after_max = $retries->schedule(1, 1000, 1000, 'opaque-mapping-generation', BookingSyncRetryPolicy::MAX_DELAY_SECONDS);
same(BookingSyncRetryPolicy::MAX_DELAY_SECONDS, $retry_after_max['delay'], 'An in-bound Retry-After may use the six-hour ceiling.');
$retry_after_too_large = $retries->schedule(1, 1000, 1000, 'opaque-mapping-generation', BookingSyncRetryPolicy::MAX_DELAY_SECONDS + 1);
check(!$retry_after_too_large['retryable'], 'Retry-After beyond the delay bound stops instead of retrying early.');
same('retry_after_exceeds_delay_bound', $retry_after_too_large['reason'], 'Out-of-bound Retry-After has a fixed reason.');
check($retries->schedule(1, 1000, 1000 + BookingSyncRetryPolicy::MAX_WINDOW_SECONDS - 1, 'opaque-mapping-generation')['retryable'] === FALSE, 'The 48-hour boundary is exclusive.');

// Claiming a malformed/excess-age retry atomically terminalizes it so a
// selector cannot hot-loop the same permanently ineligible row.
$clock->set(1000);
$attempt_limit = initialRecord($lifecycle, 70, BookingSyncOperation::CREATE);
$attempt_limit['state'] = BookingSyncState::RETRYABLE_FAILURE;
$attempt_limit['attempt_count'] = BookingSyncRetryPolicy::MAX_ATTEMPTS;
$attempt_limit['next_retry_at'] = 1000;
$attempt_limit['claim_mode'] = BookingSyncLifecycle::CLAIM_MODE_WORK;
$attempt_limit_error = RedactedError::normalize('transient_network_failure');
$attempt_limit['last_error_code'] = $attempt_limit_error['code'];
$attempt_limit['last_error_summary'] = $attempt_limit_error['summary'];
$terminal_attempt = $lifecycle->claim($attempt_limit, $worker_a);
check($terminal_attempt['success'], 'An attempt-exhausted retry produces a terminal CAS mutation.');
same(BookingSyncState::PERMANENT_FAILURE, $terminal_attempt['record']['state'], 'Attempt-exhausted row becomes permanent.');
same(NULL, $terminal_attempt['record']['next_retry_at'], 'Attempt exhaustion clears retry scheduling.');
$terminal_attempt_store = new MemoryCasStore($attempt_limit);
check($terminal_attempt_store->apply($terminal_attempt), 'Attempt exhaustion persists atomically.');

$window_limit = initialRecord($lifecycle, 71, BookingSyncOperation::CREATE);
$window_limit['state'] = BookingSyncState::RETRYABLE_FAILURE;
$window_limit['attempt_count'] = 1;
$window_limit['next_retry_at'] = 1000;
$window_limit['claim_mode'] = BookingSyncLifecycle::CLAIM_MODE_WORK;
$window_limit['last_error_code'] = $attempt_limit_error['code'];
$window_limit['last_error_summary'] = $attempt_limit_error['summary'];
$clock->set(1000 + BookingSyncRetryPolicy::MAX_WINDOW_SECONDS);
$terminal_window = $lifecycle->claim($window_limit, $worker_a);
check($terminal_window['success'], 'An age-exhausted retry produces a terminal CAS mutation.');
same(BookingSyncState::PERMANENT_FAILURE, $terminal_window['record']['state'], 'Age-exhausted row becomes permanent.');
same('retry_exhausted', $terminal_window['record']['last_error_code'], 'Terminal retry stores only a fixed redacted code.');

// Pure failure classification: 429, 5xx, timeout positions, auth, permanent.
same(BookingSyncState::RETRYABLE_FAILURE, $classifier->classify(BookingSyncFailureClassifier::TYPE_HTTP, 429)['state'], '429 must retry.');
same(BookingSyncState::RETRYABLE_FAILURE, $classifier->classify(BookingSyncFailureClassifier::TYPE_HTTP, 503, FALSE)['state'], 'Pre-result 5xx must retry.');
same(BookingSyncState::RECONCILIATION_REQUIRED, $classifier->classify(BookingSyncFailureClassifier::TYPE_HTTP, 503, TRUE)['state'], 'Possibly applied 5xx must reconcile.');
same('timeout_before_request', $classifier->classify(BookingSyncFailureClassifier::TYPE_TIMEOUT, NULL, FALSE)['error_code'], 'Timeout before request is retryable and explicit.');
same('ambiguous_remote_result', $classifier->classify(BookingSyncFailureClassifier::TYPE_TIMEOUT, NULL, TRUE)['error_code'], 'Timeout after possible success is ambiguous.');
same('temporary_authentication', $classifier->classify(BookingSyncFailureClassifier::TYPE_HTTP, 401)['error_code'], 'Temporary auth category is bounded and redacted.');
same(BookingSyncState::PERMANENT_FAILURE, $classifier->classify(BookingSyncFailureClassifier::TYPE_PAYLOAD)['state'], 'Invalid payload is permanent.');
same(BookingSyncState::RECONCILIATION_REQUIRED, $classifier->classify(BookingSyncFailureClassifier::TYPE_HTTP, 412)['state'], 'ETag conflict requires reconciliation.');

// Failure finalization schedules a durable retry and attempt 10 stops.
$clock->set(1000);
$failure_processing = claimedRecord($lifecycle, 8, BookingSyncOperation::CREATE, $worker_a);
$retry_failure = $lifecycle->finalizeFailure($failure_processing, $worker_a, BookingSyncFailureClassifier::TYPE_HTTP, 429);
check($retry_failure['success'], 'Retryable failure finalization succeeds.');
same(BookingSyncState::RETRYABLE_FAILURE, $retry_failure['record']['state'], 'Retryable failure state persists.');
check($retry_failure['record']['next_retry_at'] > 1000, 'Retry timestamp is durable and future.');
$last_attempt_processing = claimedRecord($lifecycle, 9, BookingSyncOperation::CREATE, $worker_a, 9);
$exhausted = $lifecycle->finalizeFailure($last_attempt_processing, $worker_a, BookingSyncFailureClassifier::TYPE_HTTP, 429);
same(BookingSyncState::PERMANENT_FAILURE, $exhausted['record']['state'], 'Attempt 10 becomes permanent.');
same('retry_exhausted', $exhausted['record']['last_error_code'], 'Exhaustion code is redacted.');
$ambiguous = $lifecycle->finalizeFailure(claimedRecord($lifecycle, 10, BookingSyncOperation::CREATE, $worker_a), $worker_a, BookingSyncFailureClassifier::TYPE_TIMEOUT, NULL, TRUE);
same(BookingSyncState::RECONCILIATION_REQUIRED, $ambiguous['record']['state'], 'Ambiguous result requires reconciliation.');

// Sequential lifecycle attempts 1 through 10 preserve the retry schedule and
// stop on the tenth claim without resetting the retry window.
$clock->set(1000);
$sequential = initialRecord($lifecycle, 72, BookingSyncOperation::CREATE);
for ($attempt = 1; $attempt <= BookingSyncRetryPolicy::MAX_ATTEMPTS; $attempt++) {
  if ($sequential['next_retry_at'] !== NULL) {
    $clock->set((int) $sequential['next_retry_at']);
  }
  $claim = $lifecycle->claim($sequential, $worker_a);
  check($claim['success'], 'Sequential claim must succeed within budget.');
  same($attempt, $claim['record']['attempt_count'], 'Sequential attempt count mismatch.');
  $claim_store = new MemoryCasStore($sequential);
  check($claim_store->apply($claim), 'Sequential claim CAS must apply.');
  $failure = $lifecycle->finalizeFailure($claim_store->record(), $worker_a, BookingSyncFailureClassifier::TYPE_HTTP, 429);
  check($failure['success'], 'Sequential retry finalization must succeed.');
  $failure_store = new MemoryCasStore($claim_store->record());
  check($failure_store->apply($failure), 'Sequential finalization CAS must apply.');
  $sequential = $failure_store->record();
  same($attempt < BookingSyncRetryPolicy::MAX_ATTEMPTS ? BookingSyncState::RETRYABLE_FAILURE : BookingSyncState::PERMANENT_FAILURE, $sequential['state'], 'Sequential retry terminal state mismatch.');
}
same(1000, $sequential['retry_window_started_at'], 'Sequential retries retain the original 48-hour window anchor.');

// Newer intent invalidates finalization but does not steal the active lease.
$clock->set(1000);
$old_processing = claimedRecord($lifecycle, 11, BookingSyncOperation::CREATE, $worker_a);
$new_desire = $lifecycle->recordNewDesiredOperation($old_processing, BookingSyncOperation::CANCEL, 2);
check($new_desire['success'], 'Newer cancel intent is durable during processing.');
same(BookingSyncState::PROCESSING, $new_desire['record']['state'], 'New intent does not steal processing state.');
same(BookingSyncOperation::CANCEL, $new_desire['record']['operation'], 'Latest operation is retained.');
same(BookingSyncOperation::CREATE, $new_desire['record']['processing_operation'], 'Claimed operation snapshot is immutable.');
check(!$lifecycle->finalizeSuccess($new_desire['record'], $worker_a, eventIdFor(11))['success'], 'Reloaded stale owner cannot finalize a newer generation.');
$new_desire_store = new MemoryCasStore($old_processing);
$stale_success = $lifecycle->finalizeSuccess($old_processing, $worker_a, eventIdFor(11), '"etag-old"');
check($new_desire_store->apply($new_desire), 'Newer desired generation wins CAS.');
check(!$new_desire_store->apply($stale_success), 'Stale finalizer changes no state.');
check(!$lifecycle->recordNewDesiredOperation($new_desire['record'], BookingSyncOperation::CANCEL, 2)['success'], 'Duplicate desired revision is an unchanged idempotent rejection.');
$clock->set(1121);
$new_desire_recovery = $lifecycle->recoverExpiredLease($new_desire_store->record(), $worker_b);
check($new_desire_recovery['success'], 'A newer-intent row remains recoverable after its old lease expires.');
check($new_desire_store->apply($new_desire_recovery), 'Newer-intent lease recovery applies atomically.');
same(BookingSyncState::RECONCILIATION_REQUIRED, $new_desire_store->record()['state'], 'Definitive-result deferral conservatively reconciles after newer intent.');
same(BookingSyncOperation::CANCEL, $new_desire_store->record()['operation'], 'Recovery preserves the newer cancel intent.');

// Coalescing never flips CREATE/UPDATE remote-existence semantics merely due
// to a repeated booking edit.
$clock->set(1200);
$queued_create = initialRecord($lifecycle, 73, BookingSyncOperation::CREATE);
$coalesced_create = $lifecycle->recordNewDesiredOperation($queued_create, BookingSyncOperation::UPDATE, 2);
same(BookingSyncOperation::CREATE, $coalesced_create['record']['operation'], 'Queued create remains create after an edit.');
$queued_update = initialRecord($lifecycle, 74, BookingSyncOperation::UPDATE);
$coalesced_update = $lifecycle->recordNewDesiredOperation($queued_update, BookingSyncOperation::CREATE, 2);
same(BookingSyncOperation::UPDATE, $coalesced_update['record']['operation'], 'Queued update cannot silently become create.');

// Expired leases are recoverable by a distinct worker and always reconcile in
// this no-dispatch-marker phase. Clock rollback fails closed.
$clock->set(1000);
$lease_record = claimedRecord($lifecycle, 12, BookingSyncOperation::CREATE, $worker_a);
$clock->set(900);
$rollback_renewal = $lifecycle->renew($lease_record, $worker_a);
check(!$rollback_renewal['success'] && $rollback_renewal['reason'] === 'clock_rollback_detected', 'Clock rollback rejects renewal without changing state.');
$clock->set(1120);
$owner_timeout = $lifecycle->finalizeSuccess($lease_record, $worker_a, eventIdFor(12));
check($owner_timeout['success'], 'Exact lease expiry produces a durable reconciliation mutation.');
$owner_timeout_store = new MemoryCasStore($lease_record);
check($owner_timeout_store->apply($owner_timeout), 'Owner-observed timeout persists atomically.');
same(BookingSyncState::RECONCILIATION_REQUIRED, $owner_timeout_store->record()['state'], 'An owner cannot finalize success at lease expiry.');
$clock->set(900);
check(!$lifecycle->finalizeSuccess($lease_record, $worker_a, eventIdFor(12))['success'], 'Rollback cannot resurrect a timed-out lease plan.');

$clock->set(1000);
$recovery_record = claimedRecord($lifecycle, 13, BookingSyncOperation::CREATE, $worker_a);
$clock->set(1121);
$same_owner_recovery = $lifecycle->recoverExpiredLease($recovery_record, $worker_a);
check(!$same_owner_recovery['success'], 'The expired owner cannot masquerade as a recovery worker.');
$recovered = $lifecycle->recoverExpiredLease($recovery_record, $worker_b);
check($recovered['success'], 'A different worker recovers an expired lease.');
same(BookingSyncState::RECONCILIATION_REQUIRED, $recovered['record']['state'], 'Expired lease fails closed to reconciliation.');
same('lease_expired_ambiguous', $recovered['record']['last_error_code'], 'Lease recovery stores only a fixed error code.');
$recovery_store = new MemoryCasStore($recovery_record);
$late_owner = $lifecycle->finalizeSuccess($recovery_record, $worker_a, eventIdFor(13));
check($recovery_store->apply($recovered), 'Recovery wins its duplicate-worker race.');
check(!$recovery_store->apply($late_owner), 'Late owner cannot overwrite recovered state.');

// Requeue after a newer desired generation from synced and cancelled.
$synced_record = $success['record'];
$synced_generation = $synced_record['generation'];
$clock->set(1200);
$requeue_update = $lifecycle->recordNewDesiredOperation($synced_record, BookingSyncOperation::UPDATE, 2);
check($requeue_update['success'], 'A newer update requeues a synced record.');
same(BookingSyncState::QUEUED, $requeue_update['record']['state'], 'Synced update returns to queued.');
same($synced_generation + 1, $requeue_update['record']['generation'], 'New desire increments CAS generation.');
$restored = $lifecycle->recordNewDesiredOperation($cancel_success['record'], BookingSyncOperation::CREATE, 2);
same(BookingSyncState::RECONCILIATION_REQUIRED, $restored['record']['state'], 'Restoring a deleted event requires explicit incarnation reconciliation.');

// Diagnostic output exposes only the explicit allowlist and never the event ID
// or fixed redacted summary.
$diagnostic_row = $retry_failure['record'];
$diagnostic_row['google_event_id'] = 'event_private_fixture';
$safe_view = BookingSyncDiagnosticReader::safeView($diagnostic_row);
same([
  'record_id',
  'reservation_reference',
  'operation',
  'state',
  'attempts',
  'last_attempt',
  'next_retry',
  'lease_expiry',
  'has_remote_event_id',
  'error_code',
], array_keys($safe_view), 'Diagnostic fields must match the allowlist exactly.');
check($safe_view['has_remote_event_id'] === TRUE, 'Diagnostic exposes only event-ID presence.');
containsNoSensitiveValue($safe_view, ['event_private_fixture', 'fixture-source', 'Bearer ', 'Authorization'], 'Diagnostic redaction');
$diagnostic_row['last_error_code'] = 'raw_remote_body';
same('unclassified_failure', BookingSyncDiagnosticReader::safeView($diagnostic_row)['error_code'], 'Unknown stored errors fail closed.');

// Legacy rows remain read-only plans: IDs/linkage are preserved in place,
// ambiguous and malformed facts are quarantined without copying raw values.
$legacy = new LegacyBookingSyncClassifier();
$malformed = $legacy->classify([
  'sync_status' => 'mystery',
  'sync_action' => 'unknown',
  'submission_uuid' => 'fixture-legacy-source',
  'google_event_id' => 'event_legacy_fixture',
  'last_error' => 'raw-private-fixture-error',
], 'test_env_01');
same(BookingSyncState::RECONCILIATION_REQUIRED, $malformed['state'], 'Malformed legacy row is quarantined.');
same(NULL, $malformed['operation'], 'Ambiguous legacy operation is not guessed.');
check($malformed['preserve_existing_remote_event_id'], 'Legacy remote event ID is preserved in place.');
containsNoSensitiveValue($malformed, ['fixture-legacy-source', 'event_legacy_fixture', 'raw-private-fixture-error'], 'Legacy classification redaction');
$legacy_error = $legacy->classify([
  'sync_status' => 'error',
  'sync_action' => 'pending_update',
  'submission_uuid' => 'fixture-legacy-error',
  'google_event_id' => 'event_legacy_error',
], 'test_env_01', FALSE);
same(BookingSyncState::PERMANENT_FAILURE, $legacy_error['state'], 'Unresolved legacy failure stays visible.');
$legacy_pending_without_id = $legacy->classify([
  'sync_status' => 'pending',
  'sync_action' => 'pending_create',
  'submission_uuid' => 'fixture-legacy-pending',
  'google_event_id' => NULL,
], 'test_env_01', FALSE);
same(BookingSyncState::RECONCILIATION_REQUIRED, $legacy_pending_without_id['state'], 'Pending legacy create without an ID never assumes no prior dispatch.');
check($legacy_pending_without_id['requires_operator_review'], 'Ambiguous legacy create requires operator review.');
$legacy_pending_with_unbound_target = $legacy->classify([
  'sync_status' => 'pending',
  'sync_action' => 'pending_update',
  'submission_uuid' => 'fixture-legacy-unbound',
  'google_event_id' => 'event_legacy_unbound',
], 'test_env_01', FALSE);
same(BookingSyncState::RECONCILIATION_REQUIRED, $legacy_pending_with_unbound_target['state'], 'Legacy pending work requires separately verified target binding.');
$legacy_pending_verified = $legacy->classify([
  'sync_status' => 'pending',
  'sync_action' => 'pending_update',
  'submission_uuid' => 'fixture-legacy-verified',
  'google_event_id' => 'event_legacy_verified',
], 'test_env_01', FALSE, TRUE);
same(BookingSyncState::QUEUED, $legacy_pending_verified['state'], 'Verified target plus source truth may produce a future migration plan.');
$legacy_missing_source = $legacy->classify([
  'sync_status' => 'pending',
  'sync_action' => 'pending_update',
  'submission_uuid' => '',
  'google_event_id' => 'event_legacy_source',
], 'test_env_01', FALSE, TRUE);
same(BookingSyncState::RECONCILIATION_REQUIRED, $legacy_missing_source['state'], 'Missing legacy source identity is quarantined.');
same('legacy_source_identity_invalid', $legacy_missing_source['error_code'], 'Missing legacy source uses a fixed redacted code.');
$legacy_invalid_event = $legacy->classify([
  'sync_status' => 'pending',
  'sync_action' => 'pending_update',
  'submission_uuid' => 'fixture-legacy-invalid-event',
  'google_event_id' => 'INVALID EVENT ID',
], 'test_env_01', FALSE, TRUE);
same(BookingSyncState::RECONCILIATION_REQUIRED, $legacy_invalid_event['state'], 'Malformed legacy event identity is quarantined.');
same('legacy_event_id_invalid', $legacy_invalid_event['error_code'], 'Malformed legacy event uses a fixed redacted code.');
check($legacy_invalid_event['preserve_existing_remote_event_id'], 'Malformed legacy event identity remains preserved in place.');

// Activation and source-level tripwires: cron never resolves the worker, and a
// manual call encounters the hard boundary before config, rows, or credentials.
$boundary = new GoogleCalendarActivationBoundary();
check(!$boundary->allowsRemoteProcessing(), 'Remote processing boundary must remain closed.');
same('state_foundation_inactive', $boundary->reasonCode(), 'Activation hold reason is stable.');
$disabled_client = new GoogleCalendarClient();
check(!$disabled_client->hasCredentials(), 'Direct client credential probing is disabled.');
foreach ([
  static function () use ($disabled_client): void {
    $disabled_client->createEvent('calendar-forbidden', []);
  },
  static function () use ($disabled_client): void {
    $disabled_client->updateEvent('calendar-forbidden', 'event-forbidden', []);
  },
  static function () use ($disabled_client): void {
    $disabled_client->deleteEvent('calendar-forbidden', 'event-forbidden');
  },
] as $remote_call) {
  try {
    $remote_call();
    check(FALSE, 'Direct client mutation must throw before remote work.');
  }
  catch (LogicException $e) {
    same('state_foundation_inactive', $e->getMessage(), 'Direct client mutation is blocked by a fixed reason.');
  }
}
$client_source = file_get_contents($module_root . '/src/GoogleCalendar/GoogleCalendarClient.php');
check(is_string($client_source), 'Disabled client source must be readable.');
foreach (['getenv(', '$_ENV', '$_SERVER', 'Authorization', 'httpClient', '->request(', 'googleapis.com'] as $forbidden_client_source) {
  check(strpos($client_source, $forbidden_client_source) === FALSE, 'Disabled client contains a credential or HTTP capability.');
}
$module_source = file_get_contents($module_root . '/unisonges_structure.module');
check(is_string($module_source), 'Module source must be readable.');
preg_match('/function unisonges_structure_cron\(\): void \{(.*?)\n\}/s', $module_source, $cron_match);
check(isset($cron_match[1]) && strpos($cron_match[1], 'processPending') === FALSE && strpos($cron_match[1], 'booking_calendar_sync') === FALSE, 'Cron must not resolve or invoke the worker.');
$worker_source = file_get_contents($module_root . '/src/GoogleCalendar/BookingCalendarSyncService.php');
check(is_string($worker_source), 'Worker source must be readable.');
$boundary_position = strpos($worker_source, 'if (!$this->activationBoundary->allowsRemoteProcessing())');
$enabled_position = strpos($worker_source, 'if (!$this->isEnabled())');
check($boundary_position !== FALSE && $enabled_position !== FALSE && $boundary_position < $enabled_position, 'Manual processing must hit the boundary before config/rows/client.');
$install_config = file_get_contents($module_root . '/config/install/unisonges_structure.google_calendar.yml');
check(is_string($install_config) && strpos($install_config, 'enabled: false') !== FALSE && strpos($install_config, 'token_provider: disabled') !== FALSE, 'Fresh-install config must be disabled.');

// Static storage guard: affected-row CAS, NULL-aware predicates, and no raw SQL.
$repository_source = file_get_contents($state_root . '/BookingSyncStateRepository.php');
check(is_string($repository_source), 'Repository source must be readable.');
check(strpos($repository_source, '$affected !== 1') !== FALSE, 'Repository must require exactly one affected row.');
check(strpos($repository_source, '->isNull($field)') !== FALSE, 'Repository must compare NULL atomically.');
check(strpos($repository_source, '$this->database->query(') === FALSE, 'Repository must not use raw SQL.');
check(strpos($repository_source, "'storage_failure'") !== FALSE, 'Repository must distinguish storage failure from CAS conflict.');
check(strpos($repository_source, 'applyIdentityPrecommit') !== FALSE, 'Event identity mutation must use its dedicated set-once path.');
preg_match('/private const OPERATIONAL_COLUMNS = \[(.*?)\];/s', $repository_source, $operational_columns_match);
preg_match('/private const MUTABLE_COLUMNS = \[(.*?)\];/s', $repository_source, $mutable_columns_match);
check(isset($operational_columns_match[1]) && strpos($operational_columns_match[1], "'google_event_id'") !== FALSE, 'Repository must load and compare the event identity.');
check(isset($mutable_columns_match[1]) && strpos($mutable_columns_match[1], "'google_event_id'") === FALSE, 'Generic mutations cannot change the set-once event identity.');

// Schema/update source guard without a Drupal bootstrap.
$schema = unisonges_structure_schema();
$table_schema = $schema['unisonges_structure_booking_gcal_sync'] ?? NULL;
check(is_array($table_schema), 'Booking state table schema must be declared.');
$foundation_fields = [
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
  'remote_etag',
  'last_error_code',
  'last_error_summary',
];
foreach ($foundation_fields as $field_name) {
  check(isset($table_schema['fields'][$field_name]), 'Foundation field is missing from hook_schema.');
  same(FALSE, $table_schema['fields'][$field_name]['not null'], 'Legacy upgrade field must remain nullable.');
}
same(
  ['state', 'next_retry_at', 'lease_expires_at', 'changed', 'id'],
  $table_schema['indexes']['gcal_state_claim'],
  'Claim index contract mismatch.'
);
same(
  ['state', 'lease_expires_at', 'id'],
  $table_schema['indexes']['gcal_lease_recovery'],
  'Recovery index contract mismatch.'
);
$update_11004_source = functionSource('unisonges_structure_update_11004');
foreach ($foundation_fields as $field_name) {
  check(strpos($update_11004_source, "'" . $field_name . "' => [") !== FALSE, 'Frozen update field is missing.');
}
check(strpos($update_11004_source, 'unisonges_structure_schema()') === FALSE, '11004 cannot depend on live hook_schema.');
check(strpos($update_11004_source, 'fieldExists(') !== FALSE && strpos($update_11004_source, 'indexExists(') !== FALSE, '11004 must guard partial reruns.');
check(strpos($update_11004_source, '->save(TRUE)') !== FALSE, 'Update config save must use trusted data.');
foreach (['unisonges_structure_update_11001', 'unisonges_structure_update_11003'] as $historical_update) {
  check(strpos(functionSource($historical_update), 'unisonges_structure_schema()') === FALSE, 'Historical table update must use a frozen schema.');
}

echo 'OK ' . $assertions . " assertions\n";
