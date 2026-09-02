<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Plans a future legacy-row migration without writing or exposing raw values.
 */
final class LegacyBookingSyncClassifier {

  private const ACTIONS = [
    'pending_create' => BookingSyncOperation::CREATE,
    'pending_update' => BookingSyncOperation::UPDATE,
    'pending_cancel' => BookingSyncOperation::CANCEL,
  ];

  /**
   * Classifies safe legacy facts for a future guarded migration.
   *
   * The caller must independently reload current Drupal truth. Passing NULL as
   * source truth deliberately quarantines a legacy pending row.
   */
  public function classify(array $legacy_row, string $environment_namespace, ?bool $source_is_cancelled = NULL, bool $target_binding_verified = FALSE): array {
    $status = isset($legacy_row['sync_status']) && is_string($legacy_row['sync_status'])
      ? trim($legacy_row['sync_status'])
      : '';
    $legacy_action = isset($legacy_row['sync_action']) && is_string($legacy_row['sync_action'])
      ? trim($legacy_row['sync_action'])
      : '';
    $operation = self::ACTIONS[$legacy_action] ?? NULL;
    $event_id = isset($legacy_row['google_event_id']) && is_string($legacy_row['google_event_id'])
      ? trim($legacy_row['google_event_id'])
      : '';
    $has_event_id = $event_id !== '';
    $reservation_reference = $this->reservationReference($legacy_row, $environment_namespace);

    if ($operation === NULL) {
      return $this->result(NULL, BookingSyncState::RECONCILIATION_REQUIRED, 'legacy_operation_ambiguous', $reservation_reference, $has_event_id, TRUE);
    }
    if ($reservation_reference === NULL) {
      return $this->result($operation, BookingSyncState::RECONCILIATION_REQUIRED, 'legacy_source_identity_invalid', NULL, $has_event_id, TRUE);
    }
    if ($has_event_id && !$this->isLegacyEventIdValid($event_id)) {
      return $this->result($operation, BookingSyncState::RECONCILIATION_REQUIRED, 'legacy_event_id_invalid', $reservation_reference, TRUE, TRUE);
    }

    if ($status === 'error') {
      return $this->result($operation, BookingSyncState::PERMANENT_FAILURE, 'legacy_failure_unresolved', $reservation_reference, $has_event_id, TRUE);
    }
    if ($status === 'synced') {
      return $this->result($operation, BookingSyncState::RECONCILIATION_REQUIRED, 'legacy_synced_unverified', $reservation_reference, $has_event_id, TRUE);
    }
    if ($status === 'skipped') {
      return $this->result($operation, BookingSyncState::RECONCILIATION_REQUIRED, 'legacy_dry_run_unverified', $reservation_reference, $has_event_id, TRUE);
    }
    if ($status !== 'pending' || $source_is_cancelled === NULL) {
      return $this->result($operation, BookingSyncState::RECONCILIATION_REQUIRED, 'legacy_status_ambiguous', $reservation_reference, $has_event_id, TRUE);
    }
    if (!$target_binding_verified) {
      return $this->result($operation, BookingSyncState::RECONCILIATION_REQUIRED, 'legacy_status_ambiguous', $reservation_reference, $has_event_id, TRUE);
    }

    if ($source_is_cancelled) {
      if (!$has_event_id) {
        return $this->result(BookingSyncOperation::CANCEL, BookingSyncState::RECONCILIATION_REQUIRED, 'legacy_status_ambiguous', $reservation_reference, FALSE, TRUE);
      }
      return $this->result(BookingSyncOperation::CANCEL, BookingSyncState::CANCEL_PENDING, NULL, $reservation_reference, TRUE, FALSE);
    }

    if (!$has_event_id) {
      return $this->result(BookingSyncOperation::CREATE, BookingSyncState::RECONCILIATION_REQUIRED, 'legacy_status_ambiguous', $reservation_reference, FALSE, TRUE);
    }

    return $this->result(BookingSyncOperation::UPDATE, BookingSyncState::QUEUED, NULL, $reservation_reference, TRUE, FALSE);
  }

  /**
   * Builds a safe opaque reference when the legacy UUID is usable.
   */
  private function reservationReference(array $legacy_row, string $environment_namespace): ?string {
    $uuid = isset($legacy_row['submission_uuid']) && is_string($legacy_row['submission_uuid'])
      ? trim($legacy_row['submission_uuid'])
      : '';
    if ($uuid === '') {
      return NULL;
    }

    try {
      return BookingSyncLifecycle::reservationReference($uuid, $environment_namespace);
    }
    catch (\InvalidArgumentException $e) {
      return NULL;
    }
  }

  /**
   * Validates only the bounded compatibility form; raw values stay in place.
   */
  private function isLegacyEventIdValid(string $event_id): bool {
    return preg_match('/^[a-z0-9_-]{5,255}$/', $event_id) === 1;
  }

  /**
   * Returns only migration fields and non-sensitive booleans.
   */
  private function result(?string $operation, string $state, ?string $error_code, ?string $reservation_reference, bool $has_event_id, bool $requires_review): array {
    $error = $error_code === NULL ? NULL : RedactedError::normalize($error_code);

    return [
      'reservation_ref' => $reservation_reference,
      'operation' => $operation,
      'state' => $state,
      'has_remote_event_id' => $has_event_id,
      'preserve_existing_remote_event_id' => $has_event_id,
      'error_code' => $error['code'] ?? NULL,
      'error_summary' => $error['summary'] ?? NULL,
      'requires_operator_review' => $requires_review,
    ];
  }

}
