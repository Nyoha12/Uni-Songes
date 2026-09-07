<?php

namespace Drupal\unisonges_structure\GoogleCalendar\State;

/**
 * Deterministic bounded retry scheduler.
 */
final class BookingSyncRetryPolicy {

  public const MAX_ATTEMPTS = 10;
  public const MAX_WINDOW_SECONDS = 172800;
  public const MAX_DELAY_SECONDS = 21600;
  public const INITIAL_DELAY_SECONDS = 60;
  public const MAX_JITTER_SECONDS = 1800;

  /**
   * Schedules the next attempt using deterministic bounded jitter.
   *
   * The seed must be an opaque stable mapping/version value. It must never be
   * a raw reservation UUID, Calendar identifier, event ID, or personal value.
   *
   * @return array{retryable: bool, reason: string, retry_at: ?int, delay: ?int, base_delay: ?int, jitter: ?int}
   *   A deterministic decision. `retry_at` never exceeds the 48-hour window.
   */
  public function schedule(int $attempt_count, int $retry_window_started_at, int $now, string $seed, ?int $retry_after_seconds = NULL): array {
    if ($attempt_count < 1 || $retry_window_started_at <= 0 || $seed === '') {
      return $this->exhausted('invalid_retry_context');
    }
    if ($attempt_count >= self::MAX_ATTEMPTS) {
      return $this->exhausted('attempt_limit_reached');
    }

    $effective_now = max($now, $retry_window_started_at);
    $deadline = $retry_window_started_at + self::MAX_WINDOW_SECONDS;
    $last_eligible_second = $deadline - 1;
    if ($effective_now >= $last_eligible_second) {
      return $this->exhausted('retry_window_exhausted');
    }

    $base_delay = min(
      self::MAX_DELAY_SECONDS,
      self::INITIAL_DELAY_SECONDS * (2 ** ($attempt_count - 1))
    );
    $jitter_limit = min(self::MAX_JITTER_SECONDS, intdiv($base_delay, 4));
    $jitter = $this->deterministicJitter($seed, $attempt_count, $jitter_limit);
    $delay = min(self::MAX_DELAY_SECONDS, $base_delay + $jitter);

    if ($retry_after_seconds !== NULL && $retry_after_seconds >= 0) {
      if ($retry_after_seconds > self::MAX_DELAY_SECONDS) {
        return $this->exhausted('retry_after_exceeds_delay_bound');
      }
      $delay = max($delay, $retry_after_seconds);
    }

    $retry_at = $effective_now + $delay;
    if ($retry_at > $last_eligible_second) {
      return $this->exhausted('retry_window_exhausted');
    }

    return [
      'retryable' => TRUE,
      'reason' => 'scheduled',
      'retry_at' => $retry_at,
      'delay' => $retry_at - $effective_now,
      'base_delay' => $base_delay,
      'jitter' => min($jitter, $retry_at - $effective_now),
    ];
  }

  /**
   * Produces stable jitter without a process-global random source.
   */
  private function deterministicJitter(string $seed, int $attempt_count, int $limit): int {
    if ($limit <= 0) {
      return 0;
    }

    $prefix = substr(hash('sha256', $seed . ':' . $attempt_count), 0, 8);
    return (int) (hexdec($prefix) % ($limit + 1));
  }

  /**
   * Returns a terminal retry decision.
   */
  private function exhausted(string $reason): array {
    return [
      'retryable' => FALSE,
      'reason' => $reason,
      'retry_at' => NULL,
      'delay' => NULL,
      'base_delay' => NULL,
      'jitter' => NULL,
    ];
  }

}
