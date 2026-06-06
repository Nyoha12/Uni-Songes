<?php

namespace Drupal\unisonges_structure\GoogleCalendar;

/**
 * Sends Google Calendar event requests for course booking sync.
 */
interface GoogleCalendarClientInterface {

  /**
   * Returns TRUE when this runtime can authenticate Google requests.
   */
  public function hasCredentials(): bool;

  /**
   * Creates an event and returns the decoded Google response.
   */
  public function createEvent(string $calendar_id, array $payload): array;

  /**
   * Updates an event and returns the decoded Google response.
   */
  public function updateEvent(string $calendar_id, string $event_id, array $payload): array;

  /**
   * Deletes an event if it still exists.
   */
  public function deleteEvent(string $calendar_id, string $event_id): void;

}
