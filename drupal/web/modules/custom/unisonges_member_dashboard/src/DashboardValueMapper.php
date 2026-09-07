<?php

declare(strict_types=1);

namespace Drupal\unisonges_member_dashboard;

/**
 * Validates source values and returns display-state keys only.
 */
final class DashboardValueMapper {

  public const BOOKING_TIMEZONE = 'Europe/Paris';

  public const RESERVATION_INSTRUMENTS = [
    'guimbarde',
    'didgeridoo',
  ];

  public const RESERVATION_MODES = [
    'visio',
    'studio',
    'domicile',
  ];

  public const RESERVATION_PLATFORMS = [
    'zoom',
    'google_meet',
    'skype',
    'whatsapp',
    'autre',
  ];

  public const PROPOSAL_TYPES = [
    'idea',
    'discussion_topic',
    'article_theme',
  ];

  public const COURSE_PRODUCT_BUNDLES = [
    'cours_essai',
    'cours_deb_inter',
    'cours_avance',
    'pack_4_deb_inter',
  ];

  /**
   * Parses a booking value without returning its raw representation.
   *
   * @return array{active: bool, start: \DateTimeImmutable}|null
   *   A validated display value, or NULL when its date/time is ambiguous.
   */
  public static function parseReservation(?string $value): ?array {
    $value = trim((string) $value);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})\|(\d+)$/', $value, $matches) !== 1) {
      return NULL;
    }

    try {
      $start = new \DateTimeImmutable(
        $matches[1] . ' ' . $matches[2],
        new \DateTimeZone(self::BOOKING_TIMEZONE),
      );
    }
    catch (\Throwable) {
      return NULL;
    }

    if ($start->format('Y-m-d H:i') !== $matches[1] . ' ' . $matches[2]) {
      return NULL;
    }

    return [
      // The reviewed booking schema uses the literal flag 1 for a live slot.
      // Any other numeric value remains conservative and non-active.
      'active' => $matches[3] === '1',
      'start' => $start,
    ];
  }

  /**
   * Maps a reservation to a conservative display-state key.
   */
  public static function reservationState(?array $reservation): string {
    return $reservation !== NULL && $reservation['active'] === TRUE
      ? 'registered'
      : 'inactive';
  }

  /**
   * Maps the reviewed order signals to a high-level display-state key.
   */
  public static function orderState(
    string $state,
    bool $is_paid,
    bool $is_manual,
    bool $has_verified_pending_right,
  ): string {
    if (in_array($state, ['canceled', 'cancelled'], TRUE)) {
      return 'cancelled';
    }
    if ($state === 'completed' && $is_paid) {
      return 'paid';
    }
    if ($state === 'completed'
      && !$is_paid
      && $is_manual
      && $has_verified_pending_right) {
      return 'pay_on_site';
    }

    return 'in_progress';
  }

  /**
   * Tests a value against an exact string allowlist.
   */
  public static function allowlistedString(mixed $value, array $allowlist): ?string {
    if (!is_string($value) || !in_array($value, $allowlist, TRUE)) {
      return NULL;
    }

    return $value;
  }

  /**
   * Returns a valid positive integer, or NULL for malformed values.
   */
  public static function positiveInteger(mixed $value): ?int {
    $value = is_int($value) ? (string) $value : $value;
    if (!is_string($value) || preg_match('/^[1-9]\d*$/D', $value) !== 1) {
      return NULL;
    }

    $number = (int) $value;
    return $number > 0 ? $number : NULL;
  }

  /**
   * Returns an inclusive usable expiry in the current business-day timezone.
   */
  public static function usableExpiry(
    string $value,
    int $current_timestamp,
    string $timezone_name,
  ): ?\DateTimeImmutable {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
      return NULL;
    }

    try {
      $timezone = new \DateTimeZone($timezone_name);
      $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
      $today = (new \DateTimeImmutable('@' . $current_timestamp))
        ->setTimezone($timezone)
        ->setTime(0, 0);
    }
    catch (\Throwable) {
      return NULL;
    }

    if (!$expiry instanceof \DateTimeImmutable || $expiry->format('Y-m-d') !== $value) {
      return NULL;
    }

    return $expiry >= $today ? $expiry : NULL;
  }

  /**
   * Returns the maximum reviewed credit index for a course order item.
   */
  public static function courseCreditCapacity(string $bundle, mixed $quantity_value): ?int {
    $quantity_value = is_int($quantity_value) ? (string) $quantity_value : $quantity_value;
    if (!is_string($quantity_value)
      || preg_match('/^\d+(?:\.\d+)?$/D', $quantity_value) !== 1) {
      return NULL;
    }
    $quantity_number = (float) $quantity_value;
    if (!is_finite($quantity_number)
      || $quantity_number <= 0
      || $quantity_number > PHP_INT_MAX) {
      return NULL;
    }
    $quantity = max(1, (int) round($quantity_number));

    return match ($bundle) {
      'cours_essai' => 1,
      'cours_deb_inter', 'cours_avance' => $quantity,
      'pack_4_deb_inter' => $quantity <= intdiv(PHP_INT_MAX, 4)
        ? 4 * $quantity
        : NULL,
      default => NULL,
    };
  }

  /**
   * Accepts only the exact free, pending shape of one usable custom-table row.
   */
  public static function isUsablePayOnSiteShape(
    string $status,
    mixed $remaining,
    mixed $submission_reference,
    mixed $consumed_at,
    mixed $paid_at,
    mixed $cancelled_at,
    int $credit_index,
    int $credit_capacity,
  ): bool {
    return $status === 'pending_payment'
      && (string) $remaining === '1'
      && $submission_reference === NULL
      && $consumed_at === NULL
      && $paid_at === NULL
      && $cancelled_at === NULL
      && $credit_index > 0
      && $credit_capacity > 0
      && $credit_index <= $credit_capacity;
  }

}
