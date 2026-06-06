<?php

namespace Drupal\unisonges_structure\GoogleCalendar;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes pending course booking rows for Google Calendar sync.
 */
class BookingCalendarSyncService {

  private const CONFIG_NAME = 'unisonges_structure.google_calendar';
  private const TABLE = 'unisonges_structure_booking_gcal_sync';
  private const PENDING_ACTIONS = [
    'pending_create',
    'pending_update',
    'pending_cancel',
  ];

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $database;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $configFactory;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  private $time;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * Google Calendar client.
   *
   * @var \Drupal\unisonges_structure\GoogleCalendar\GoogleCalendarClientInterface
   */
  private $calendarClient;

  /**
   * Constructs the booking sync service.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, TimeInterface $time, LoggerInterface $logger, GoogleCalendarClientInterface $calendar_client) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->logger = $logger;
    $this->calendarClient = $calendar_client;
  }

  /**
   * Processes pending rows during Drupal cron.
   */
  public function processPendingFromCron(): array {
    return $this->processPending();
  }

  /**
   * Processes pending Google Calendar sync rows.
   */
  public function processPending(?int $limit = NULL): array {
    $result = [
      'processed' => 0,
      'synced' => 0,
      'skipped' => 0,
      'error' => 0,
    ];

    if (!$this->isEnabled()) {
      $this->logger->debug('Google Calendar booking sync is disabled; cron skipped.');
      return $result;
    }

    if (!$this->tableExists()) {
      $this->logger->warning('Google Calendar booking sync table @table is missing; cron skipped.', [
        '@table' => self::TABLE,
      ]);
      return $result;
    }

    $limit = $limit !== NULL ? $limit : $this->getBatchSize();
    $rows = $this->loadPendingRows($limit);
    if (!$rows) {
      $this->logger->debug('Google Calendar booking sync found no pending rows.');
      return $result;
    }

    $dry_run = $this->isDryRun();
    $calendar_id = $this->getCalendarId();
    if (!$dry_run && $calendar_id === '') {
      return $this->markBatchError($rows, 'Google Calendar ID is missing from configuration.', $result);
    }

    if (!$dry_run && !$this->calendarClient->hasCredentials()) {
      return $this->markBatchError($rows, 'Google Calendar access token is missing from the configured environment variable.', $result);
    }

    foreach ($rows as $row) {
      $result['processed']++;
      try {
        $status = $this->processRow($row, $dry_run, $calendar_id);
        if (isset($result[$status])) {
          $result[$status]++;
        }
      }
      catch (\Throwable $e) {
        $this->markError((int) $row->id, $e->getMessage());
        $result['error']++;
        $this->logger->error('Google Calendar booking sync failed for row @id / sid @sid: @message', [
          '@id' => $row->id,
          '@sid' => $row->sid,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->notice('Google Calendar booking sync processed @processed rows (@synced synced, @skipped skipped, @error errors).', [
      '@processed' => $result['processed'],
      '@synced' => $result['synced'],
      '@skipped' => $result['skipped'],
      '@error' => $result['error'],
    ]);

    return $result;
  }

  /**
   * Placeholder for future inbound Google busy-slot reconciliation.
   */
  public function prepareInboundBusySlotSync(): array {
    $this->logger->debug('Inbound Google Calendar busy-slot sync is not implemented yet.');

    return [
      'processed' => 0,
      'skipped' => 0,
      'reason' => 'Inbound busy-slot sync is intentionally left as a future extension.',
    ];
  }

  /**
   * Processes one sync row.
   */
  private function processRow(object $row, bool $dry_run, string $calendar_id): string {
    $action = (string) $row->sync_action;
    if (!in_array($action, self::PENDING_ACTIONS, TRUE)) {
      $this->markSkipped((int) $row->id, 'Unsupported sync action: ' . $action);
      return 'skipped';
    }

    $event_id = trim((string) ($row->google_event_id ?? ''));

    if ($dry_run) {
      $payload = NULL;
      if ($action !== 'pending_cancel') {
        $payload = $this->resolvePayload($row);
        if ($payload === NULL) {
          throw new \RuntimeException('Unable to resolve dry-run Google Calendar payload for booking sync row.');
        }
      }

      $this->logger->notice('Dry-run Google Calendar booking sync would process @action for row @id / sid @sid (event_id=@event_id, summary=@summary, start=@start, end=@end).', [
        '@action' => $action,
        '@id' => $row->id,
        '@sid' => $row->sid,
        '@event_id' => $event_id,
        '@summary' => is_array($payload) ? (string) ($payload['summary'] ?? '') : '',
        '@start' => is_array($payload) ? (string) ($payload['start']['dateTime'] ?? '') : '',
        '@end' => is_array($payload) ? (string) ($payload['end']['dateTime'] ?? '') : '',
      ]);
      $this->markSkipped((int) $row->id, 'Dry-run: no Google Calendar request sent.');
      return 'skipped';
    }

    if ($action === 'pending_cancel') {
      if ($event_id === '') {
        $this->markSkipped((int) $row->id, 'No Google event ID available to cancel.');
        return 'skipped';
      }

      $this->calendarClient->deleteEvent($calendar_id, $event_id);
      $this->markSynced((int) $row->id);
      $this->logger->notice('Google Calendar event @event_id cancelled for booking sync row @id / sid @sid.', [
        '@event_id' => $event_id,
        '@id' => $row->id,
        '@sid' => $row->sid,
      ]);
      return 'synced';
    }

    $payload = $this->resolvePayload($row);
    if ($payload === NULL) {
      throw new \RuntimeException('Unable to resolve Google Calendar payload for booking sync row.');
    }

    if ($action === 'pending_update' && $event_id !== '') {
      $response = $this->calendarClient->updateEvent($calendar_id, $event_id, $payload);
      $event_id = (string) ($response['id'] ?? $event_id);
    }
    elseif ($action === 'pending_create' && $event_id !== '') {
      $response = $this->calendarClient->updateEvent($calendar_id, $event_id, $payload);
      $event_id = (string) ($response['id'] ?? $event_id);
    }
    else {
      $response = $this->calendarClient->createEvent($calendar_id, $payload);
      $event_id = (string) ($response['id'] ?? '');
      if ($event_id === '') {
        throw new \RuntimeException('Google Calendar create response did not include an event ID.');
      }
    }

    $payload_json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $fields = [
      'google_event_id' => $event_id,
    ];
    if (is_string($payload_json)) {
      $fields['payload_json'] = $payload_json;
    }

    $this->markSynced((int) $row->id, $fields);

    $this->logger->notice('Google Calendar booking sync @action completed for row @id / sid @sid (event_id=@event_id).', [
      '@action' => $action,
      '@id' => $row->id,
      '@sid' => $row->sid,
      '@event_id' => $event_id,
    ]);

    return 'synced';
  }

  /**
   * Loads pending rows in stable order.
   */
  private function loadPendingRows(int $limit): array {
    $limit = max(1, min(100, $limit));

    return $this->database->select(self::TABLE, 'sync')
      ->fields('sync')
      ->condition('sync_status', 'pending')
      ->condition('sync_action', self::PENDING_ACTIONS, 'IN')
      ->orderBy('changed', 'ASC')
      ->orderBy('id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Uses stored payload JSON or rebuilds it from the webform submission.
   */
  private function resolvePayload(object $row): ?array {
    $payload_json = trim((string) ($row->payload_json ?? ''));
    if ($payload_json !== '') {
      $decoded = json_decode($payload_json, TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }

    $submission = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->load((int) $row->sid);

    if (!$submission instanceof WebformSubmissionInterface) {
      return NULL;
    }

    $payload = \unisonges_structure_build_google_calendar_dry_run_payload($submission);
    return is_array($payload) ? $payload : NULL;
  }

  /**
   * Marks every row in a batch as a configuration error.
   */
  private function markBatchError(array $rows, string $message, array $result): array {
    foreach ($rows as $row) {
      $this->markError((int) $row->id, $message);
      $result['processed']++;
      $result['error']++;
    }

    $this->logger->error('Google Calendar booking sync batch failed before external calls: @message', [
      '@message' => $message,
    ]);

    return $result;
  }

  /**
   * Marks a row as synced.
   */
  private function markSynced(int $id, array $fields = []): void {
    $now = $this->time->getRequestTime();
    $this->database->update(self::TABLE)
      ->fields($fields + [
        'sync_status' => 'synced',
        'last_error' => NULL,
        'last_synced' => $now,
        'changed' => $now,
      ])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Marks a row as skipped.
   */
  private function markSkipped(int $id, string $message): void {
    $now = $this->time->getRequestTime();
    $this->database->update(self::TABLE)
      ->fields([
        'sync_status' => 'skipped',
        'last_error' => $message,
        'changed' => $now,
      ])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Marks a row as errored.
   */
  private function markError(int $id, string $message): void {
    $now = $this->time->getRequestTime();
    $this->database->update(self::TABLE)
      ->fields([
        'sync_status' => 'error',
        'last_error' => substr($message, 0, 1000),
        'changed' => $now,
      ])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Returns TRUE when the mapping table exists.
   */
  private function tableExists(): bool {
    try {
      return $this->database->schema()->tableExists(self::TABLE);
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  /**
   * Returns TRUE when outgoing sync is enabled.
   */
  private function isEnabled(): bool {
    return (bool) $this->configFactory->get(self::CONFIG_NAME)->get('enabled');
  }

  /**
   * Returns TRUE when dry-run mode is enabled.
   */
  private function isDryRun(): bool {
    $value = $this->configFactory->get(self::CONFIG_NAME)->get('dry_run');
    return $value === NULL ? TRUE : (bool) $value;
  }

  /**
   * Gets the configured Google Calendar ID.
   */
  private function getCalendarId(): string {
    return trim((string) ($this->configFactory->get(self::CONFIG_NAME)->get('calendar_id') ?: ''));
  }

  /**
   * Gets the configured cron batch size.
   */
  private function getBatchSize(): int {
    $batch_size = (int) ($this->configFactory->get(self::CONFIG_NAME)->get('batch_size') ?: 10);
    return max(1, min(100, $batch_size));
  }

}
