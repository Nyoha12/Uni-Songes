<?php

namespace Drupal\unisonges_structure\GoogleCalendar;

/**
 * Fail-closed Google Calendar client for the static state-foundation release.
 *
 * The network-capable prototype is deliberately absent from this release so a
 * direct service invocation cannot read credentials or issue an HTTP request.
 */
class GoogleCalendarClient implements GoogleCalendarClientInterface {

  /**
   * {@inheritdoc}
   */
  public function hasCredentials(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function createEvent(string $calendar_id, array $payload): array {
    throw new \LogicException('state_foundation_inactive');
  }

  /**
   * {@inheritdoc}
   */
  public function updateEvent(string $calendar_id, string $event_id, array $payload): array {
    throw new \LogicException('state_foundation_inactive');
  }

  /**
   * {@inheritdoc}
   */
  public function deleteEvent(string $calendar_id, string $event_id): void {
    throw new \LogicException('state_foundation_inactive');
  }

}
