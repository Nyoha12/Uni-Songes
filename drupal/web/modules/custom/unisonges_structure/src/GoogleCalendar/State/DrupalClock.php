<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Uses Drupal's current-time service instead of request-start time.
 */
final class DrupalClock implements ClockInterface {

  /**
   * Drupal time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  private $time;

  /**
   * Constructs the clock adapter.
   */
  public function __construct(TimeInterface $time) {
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public function now(): int {
    return (int) $this->time->getCurrentTime();
  }

}
