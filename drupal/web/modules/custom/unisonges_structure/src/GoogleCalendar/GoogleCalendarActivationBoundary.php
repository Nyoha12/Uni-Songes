<?php

namespace Drupal\unisonges_structure\GoogleCalendar;

/**
 * Hard production hold for the state-foundation phase.
 *
 * This is deliberately not configurable. A later reviewed phase must replace
 * this boundary before any worker may select rows, inspect credentials, or
 * contact Google.
 */
final class GoogleCalendarActivationBoundary {

  /**
   * Returns whether remote processing is available in this release.
   */
  public function allowsRemoteProcessing(): bool {
    return FALSE;
  }

  /**
   * Returns a non-sensitive machine-readable hold reason.
   */
  public function reasonCode(): string {
    return 'state_foundation_inactive';
  }

}
