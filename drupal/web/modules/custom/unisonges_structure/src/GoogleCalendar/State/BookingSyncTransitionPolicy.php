<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Fail-closed transition policy for the reviewed state machine.
 */
final class BookingSyncTransitionPolicy {

  public const EVENT_CLAIM = 'claim';
  public const EVENT_RECONCILIATION_CLAIM = 'reconciliation_claim';
  public const EVENT_SUCCESS = 'success';
  public const EVENT_RETRYABLE_FAILURE = 'retryable_failure';
  public const EVENT_PERMANENT_FAILURE = 'permanent_failure';
  public const EVENT_AMBIGUOUS_FAILURE = 'ambiguous_failure';
  public const EVENT_NEWER_DESIRED_OPERATION = 'newer_desired_operation';
  public const EVENT_RETRY_EXHAUSTED = 'retry_exhausted';
  public const EVENT_LEASE_EXPIRED = 'lease_expired';
  public const EVENT_LOCAL_CANCEL = 'local_cancel';

  /**
   * Evaluates one transition without changing state.
   *
   * @return array{allowed: bool, reason: string}
   *   A fixed, non-sensitive decision.
   */
  public function evaluate(string $from, string $to, string $operation, string $event, array $guards = []): array {
    if (!BookingSyncState::isValid($from) || !BookingSyncState::isValid($to)) {
      return $this->rejected('invalid_state');
    }
    if (!BookingSyncOperation::isValid($operation)) {
      return $this->rejected('invalid_operation');
    }
    if ($from === $to && $event !== self::EVENT_NEWER_DESIRED_OPERATION) {
      return $this->rejected('same_state');
    }

    switch ($event) {
      case self::EVENT_CLAIM:
        if ($to !== BookingSyncState::PROCESSING) {
          return $this->rejected('claim_target_invalid');
        }
        if ($from === BookingSyncState::QUEUED && $operation !== BookingSyncOperation::CANCEL) {
          return $this->allowed();
        }
        if ($from === BookingSyncState::CANCEL_PENDING && $operation === BookingSyncOperation::CANCEL) {
          return $this->allowed();
        }
        if ($from === BookingSyncState::RETRYABLE_FAILURE) {
          if (empty($guards['retry_due'])) {
            return $this->rejected('retry_not_due');
          }
          if (!empty($guards['reconciliation_retry']) && empty($guards['explicit_reconciliation_claim'])) {
            return $this->rejected('reconciliation_claim_required');
          }
          return $this->allowed();
        }
        return $this->rejected('claim_source_ineligible');

      case self::EVENT_RECONCILIATION_CLAIM:
        if ($from !== BookingSyncState::RECONCILIATION_REQUIRED || $to !== BookingSyncState::PROCESSING) {
          return $this->rejected('reconciliation_source_ineligible');
        }
        if (empty($guards['explicit_reconciliation_claim'])) {
          return $this->rejected('reconciliation_claim_required');
        }
        return $this->allowed();

      case self::EVENT_SUCCESS:
        if ($from !== BookingSyncState::PROCESSING) {
          return $this->rejected('success_source_invalid');
        }
        if ($operation === BookingSyncOperation::CANCEL && $to === BookingSyncState::CANCELLED) {
          return $this->allowed();
        }
        if ($operation !== BookingSyncOperation::CANCEL && $to === BookingSyncState::SYNCED) {
          return $this->allowed();
        }
        return $this->rejected('success_operation_mismatch');

      case self::EVENT_RETRYABLE_FAILURE:
        return $from === BookingSyncState::PROCESSING && $to === BookingSyncState::RETRYABLE_FAILURE
          ? $this->allowed()
          : $this->rejected('failure_transition_invalid');

      case self::EVENT_PERMANENT_FAILURE:
        return $from === BookingSyncState::PROCESSING && $to === BookingSyncState::PERMANENT_FAILURE
          ? $this->allowed()
          : $this->rejected('failure_transition_invalid');

      case self::EVENT_AMBIGUOUS_FAILURE:
        return $from === BookingSyncState::PROCESSING && $to === BookingSyncState::RECONCILIATION_REQUIRED
          ? $this->allowed()
          : $this->rejected('ambiguity_transition_invalid');

      case self::EVENT_RETRY_EXHAUSTED:
        return $from === BookingSyncState::RETRYABLE_FAILURE && $to === BookingSyncState::PERMANENT_FAILURE
          ? $this->allowed()
          : $this->rejected('retry_exhaustion_transition_invalid');

      case self::EVENT_LEASE_EXPIRED:
        if (empty($guards['lease_expired'])) {
          return $this->rejected('lease_not_expired');
        }
        return $from === BookingSyncState::PROCESSING && $to === BookingSyncState::RECONCILIATION_REQUIRED
          ? $this->allowed()
          : $this->rejected('lease_recovery_transition_invalid');

      case self::EVENT_LOCAL_CANCEL:
        if ($from !== BookingSyncState::CANCEL_PENDING
          || $to !== BookingSyncState::CANCELLED
          || $operation !== BookingSyncOperation::CANCEL) {
          return $this->rejected('local_cancel_transition_invalid');
        }
        if (empty($guards['no_remote_identity']) || empty($guards['never_claimed'])) {
          return $this->rejected('local_cancel_proof_required');
        }
        return $this->allowed();

      case self::EVENT_NEWER_DESIRED_OPERATION:
        if (empty($guards['newer_desired_generation'])) {
          return $this->rejected('newer_desired_generation_required');
        }
        return $this->evaluateNewerDesiredOperation($from, $to, $operation);
    }

    return $this->rejected('event_not_allowed');
  }

  /**
   * Evaluates transitions caused by a newer desired operation.
   */
  private function evaluateNewerDesiredOperation(string $from, string $to, string $operation): array {
    if ($from === $to && in_array($from, [
      BookingSyncState::PROCESSING,
      BookingSyncState::PERMANENT_FAILURE,
      BookingSyncState::RECONCILIATION_REQUIRED,
    ], TRUE)) {
      return $this->allowed();
    }
    if ($from === $to && $from === BookingSyncState::QUEUED && $operation !== BookingSyncOperation::CANCEL) {
      return $this->allowed();
    }
    if ($from === $to && $from === BookingSyncState::CANCEL_PENDING && $operation === BookingSyncOperation::CANCEL) {
      return $this->allowed();
    }

    if ($from === BookingSyncState::SYNCED) {
      if ($operation === BookingSyncOperation::UPDATE && $to === BookingSyncState::QUEUED) {
        return $this->allowed();
      }
      if ($operation === BookingSyncOperation::CANCEL && $to === BookingSyncState::CANCEL_PENDING) {
        return $this->allowed();
      }
    }

    if ($from === BookingSyncState::CANCELLED && $operation === BookingSyncOperation::CREATE && $to === BookingSyncState::RECONCILIATION_REQUIRED) {
      return $this->allowed();
    }

    if ($from === BookingSyncState::QUEUED && $operation === BookingSyncOperation::CANCEL && $to === BookingSyncState::CANCEL_PENDING) {
      return $this->allowed();
    }

    if ($from === BookingSyncState::CANCEL_PENDING && $operation === BookingSyncOperation::UPDATE && $to === BookingSyncState::QUEUED) {
      return $this->allowed();
    }

    if ($from === BookingSyncState::RETRYABLE_FAILURE) {
      if ($operation === BookingSyncOperation::CANCEL && $to === BookingSyncState::CANCEL_PENDING) {
        return $this->allowed();
      }
      if ($operation !== BookingSyncOperation::CANCEL && $to === BookingSyncState::QUEUED) {
        return $this->allowed();
      }
    }

    return $this->rejected('newer_operation_transition_invalid');
  }

  /**
   * Returns an allowed decision.
   */
  private function allowed(): array {
    return ['allowed' => TRUE, 'reason' => 'allowed'];
  }

  /**
   * Returns a rejected decision.
   */
  private function rejected(string $reason): array {
    return ['allowed' => FALSE, 'reason' => $reason];
  }

}
