<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Replaceable wall clock for lease and retry decisions.
 */
interface ClockInterface {

  /**
   * Returns the current Unix timestamp.
   */
  public function now(): int;

}
