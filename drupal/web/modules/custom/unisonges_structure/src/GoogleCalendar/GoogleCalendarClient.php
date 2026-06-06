<?php

namespace Drupal\unisonges_structure\GoogleCalendar;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal HTTP client for Google Calendar v3 event operations.
 */
class GoogleCalendarClient implements GoogleCalendarClientInterface {

  private const CONFIG_NAME = 'unisonges_structure.google_calendar';
  private const API_BASE = 'https://www.googleapis.com/calendar/v3/calendars/';

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private $httpClient;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $configFactory;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * Constructs the Google Calendar client.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function hasCredentials(): bool {
    return $this->getAccessToken() !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function createEvent(string $calendar_id, array $payload): array {
    return $this->request('POST', $this->eventsUri($calendar_id), $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function updateEvent(string $calendar_id, string $event_id, array $payload): array {
    return $this->request('PUT', $this->eventUri($calendar_id, $event_id), $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteEvent(string $calendar_id, string $event_id): void {
    $token = $this->getAccessToken();
    if ($token === '') {
      throw new \RuntimeException('Google Calendar access token is missing.');
    }

    $response = $this->httpClient->request('DELETE', $this->eventUri($calendar_id, $event_id), [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
      ],
      'query' => [
        'sendUpdates' => 'none',
      ],
      'http_errors' => FALSE,
      'timeout' => 10,
    ]);

    $status_code = (int) $response->getStatusCode();
    if (($status_code >= 200 && $status_code < 300) || in_array($status_code, [404, 410], TRUE)) {
      return;
    }

    throw new \RuntimeException(sprintf(
      'Google Calendar DELETE failed with HTTP %d: %s',
      $status_code,
      $this->summarizeBody((string) $response->getBody())
    ));
  }

  /**
   * Sends an authenticated JSON request.
   */
  private function request(string $method, string $uri, array $payload): array {
    $token = $this->getAccessToken();
    if ($token === '') {
      throw new \RuntimeException('Google Calendar access token is missing.');
    }

    $response = $this->httpClient->request($method, $uri, [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
      ],
      'query' => [
        'sendUpdates' => 'none',
      ],
      'json' => $payload,
      'http_errors' => FALSE,
      'timeout' => 10,
    ]);

    $status_code = (int) $response->getStatusCode();
    $body = (string) $response->getBody();
    if ($status_code < 200 || $status_code >= 300) {
      throw new \RuntimeException(sprintf(
        'Google Calendar %s failed with HTTP %d: %s',
        $method,
        $status_code,
        $this->summarizeBody($body)
      ));
    }

    if ($body === '') {
      return [];
    }

    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      $this->logger->warning('Google Calendar returned a non-JSON response body for @method.', [
        '@method' => $method,
      ]);
      return [];
    }

    return $decoded;
  }

  /**
   * Builds the events collection URI.
   */
  private function eventsUri(string $calendar_id): string {
    return self::API_BASE . rawurlencode($calendar_id) . '/events';
  }

  /**
   * Builds a single event URI.
   */
  private function eventUri(string $calendar_id, string $event_id): string {
    return $this->eventsUri($calendar_id) . '/' . rawurlencode($event_id);
  }

  /**
   * Gets a runtime access token without reading secrets from Drupal config.
   */
  private function getAccessToken(): string {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    if ((string) ($config->get('token_provider') ?: 'env_access_token') !== 'env_access_token') {
      return '';
    }

    $env_var = (string) ($config->get('access_token_env_var') ?: 'UNISONGES_GCAL_ACCESS_TOKEN');
    $token = getenv($env_var);
    if (is_string($token) && trim($token) !== '') {
      return trim($token);
    }

    foreach ([$_ENV, $_SERVER] as $source) {
      if (isset($source[$env_var]) && is_string($source[$env_var]) && trim($source[$env_var]) !== '') {
        return trim($source[$env_var]);
      }
    }

    return '';
  }

  /**
   * Keeps remote error logs useful without dumping very large response bodies.
   */
  private function summarizeBody(string $body): string {
    $body = trim($body);
    if ($body === '') {
      return '(empty response body)';
    }

    return substr($body, 0, 500);
  }

}
