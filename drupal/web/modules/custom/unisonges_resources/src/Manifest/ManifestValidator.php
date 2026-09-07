<?php

declare(strict_types=1);

namespace Drupal\unisonges_resources\Manifest;

/**
 * Strict, side-effect-free validator for Resources manifest schema version 1.
 */
final class ManifestValidator {

  public const MAX_PUBLISHED_RESOURCES = 20;

  private const MAX_URL_BYTES = 2048;

  private const TEXT_LIMITS = [
    'title' => 160,
    'description' => 500,
    'theme' => 80,
    'type' => 80,
    'audience' => 160,
    'editorial_note' => 500,
  ];

  private const REQUIRED_FIELDS = [
    'id',
    'title',
    'description',
    'theme',
    'type',
    'language',
    'published',
  ];

  private const OPTIONAL_FIELDS = [
    'url',
    'audience',
    'editorial_note',
    'last_verified',
    'order',
  ];

  private const TRACKING_KEYS = [
    '_ga',
    'aff',
    'aff_id',
    'affiliate',
    'affiliate_id',
    'campaign',
    'campaign_id',
    'dclid',
    'fbclid',
    'gad_source',
    'gclid',
    'gbraid',
    'igshid',
    'li_fat_id',
    'mc_cid',
    'mc_eid',
    'msclkid',
    'partner',
    'partner_id',
    'ref',
    'referral',
    'referrer',
    'srsltid',
    'tag',
    'ttclid',
    'twclid',
    'wbraid',
    'yclid',
  ];

  private const CREDENTIAL_KEYS = [
    'access_token',
    'api_key',
    'apikey',
    'auth',
    'authorization',
    'client_secret',
    'credential',
    'credentials',
    'jwt',
    'key',
    'oauth_token',
    'password',
    'passwd',
    'phpsessid',
    'jsessionid',
    'saml_request',
    'saml_response',
    'secret',
    'session',
    'session_id',
    'sid',
    'sig',
    'signature',
    'token',
    'x_amz_credential',
    'x_amz_signature',
  ];

  private const RESERVED_SUFFIXES = [
    'example',
    'home.arpa',
    'internal',
    'invalid',
    'local',
    'localdomain',
    'localhost',
    'test',
  ];

  public function __construct(
    private bool $allowExampleInvalid = FALSE,
  ) {}

  /**
   * Returns the only validator variant allowed to accept fixture URLs.
   */
  public static function forTestFixtures(): self {
    return new self(TRUE);
  }

  /**
   * Validates the complete decoded manifest and never returns partial data.
   */
  public function validate(mixed $data): ManifestValidationResult {
    $errors = [];
    if (!class_exists(\Normalizer::class) || !function_exists('idn_to_ascii')) {
      $this->error($errors, 'runtime', 'intl_dependency_missing', 'Unicode NFC and UTS #46 IDNA support are required.');
      return ManifestValidationResult::invalid($errors);
    }
    if (!$data instanceof \stdClass && (!is_array($data) || array_is_list($data))) {
      $this->error($errors, 'manifest', 'top_level_mapping', 'The manifest root must be a mapping.');
      return ManifestValidationResult::invalid($errors);
    }
    if ($data instanceof \stdClass) {
      $data = get_object_vars($data);
    }

    $this->exactKeys(
      $data,
      ['schema_version', 'catalogue_approved', 'resources'],
      ['schema_version', 'catalogue_approved', 'resources'],
      'manifest',
      $errors,
    );
    if (($data['schema_version'] ?? NULL) !== 1) {
      $this->error($errors, 'schema_version', 'schema_version', 'schema_version must be the integer 1.');
    }
    $approved = $data['catalogue_approved'] ?? NULL;
    if (!is_bool($approved)) {
      $this->error($errors, 'catalogue_approved', 'boolean', 'catalogue_approved must be an explicit boolean.');
    }
    $raw_resources = $data['resources'] ?? NULL;
    if (!is_array($raw_resources) || !array_is_list($raw_resources)) {
      $this->error($errors, 'resources', 'list', 'resources must be a YAML list.');
      return ManifestValidationResult::invalid($errors);
    }

    $resources = [];
    $seen_ids = [];
    $seen_urls = [];
    $seen_orders = [];
    $published_count = 0;
    $published_with_order = 0;
    $observed_themes = [];
    $allowed_fields = array_merge(self::REQUIRED_FIELDS, self::OPTIONAL_FIELDS);

    foreach ($raw_resources as $index => $raw) {
      $path = sprintf('resources[%d]', $index);
      $before = count($errors);
      if (!$raw instanceof \stdClass && (!is_array($raw) || array_is_list($raw))) {
        $this->error($errors, $path, 'resource_mapping', 'Each resource must be a mapping.');
        continue;
      }
      if ($raw instanceof \stdClass) {
        $raw = get_object_vars($raw);
      }
      $this->exactKeys($raw, self::REQUIRED_FIELDS, $allowed_fields, $path, $errors);

      $published = $raw['published'] ?? NULL;
      if (!is_bool($published)) {
        $this->error($errors, $path . '.published', 'boolean', 'published must be an explicit boolean.');
      }
      elseif ($published) {
        $published_count++;
      }

      $id = $this->id($raw['id'] ?? NULL, $path . '.id', $errors);
      if ($id !== NULL) {
        if (isset($seen_ids[$id])) {
          $this->error($errors, $path . '.id', 'duplicate_id', 'Resource IDs must be unique.');
        }
        $seen_ids[$id] = TRUE;
      }

      $title = $this->text($raw['title'] ?? NULL, $path . '.title', self::TEXT_LIMITS['title'], $errors);
      $description = $this->text($raw['description'] ?? NULL, $path . '.description', self::TEXT_LIMITS['description'], $errors);
      $theme = $this->text($raw['theme'] ?? NULL, $path . '.theme', self::TEXT_LIMITS['theme'], $errors);
      $type = $this->text($raw['type'] ?? NULL, $path . '.type', self::TEXT_LIMITS['type'], $errors);
      $language = $this->language($raw['language'] ?? NULL, $path . '.language', $errors);
      if ($published === TRUE && $theme !== NULL) {
        $observed_themes[$theme] = TRUE;
      }

      $audience = $this->optionalText($raw, 'audience', $path, $errors);
      $editorial_note = $this->optionalText($raw, 'editorial_note', $path, $errors);
      $last_verified = $this->optionalDate($raw, $path, $errors);
      $order = $this->optionalOrder($raw, $path, $errors);
      if ($published === TRUE && $order !== NULL) {
        $published_with_order++;
        if (isset($seen_orders[$order])) {
          $this->error($errors, $path . '.order', 'duplicate_order', 'Published order values must be unique.');
        }
        $seen_orders[$order] = TRUE;
      }

      $url = NULL;
      if (!array_key_exists('url', $raw)) {
        if ($published === TRUE) {
          $this->error($errors, $path . '.url', 'required', 'A published resource requires a URL.');
        }
      }
      else {
        [$url, $normalized_url] = $this->url($raw['url'], $path . '.url', $errors);
        if ($normalized_url !== NULL) {
          if (isset($seen_urls[$normalized_url])) {
            $this->error($errors, $path . '.url', 'duplicate_url', 'Normalized resource URLs must be unique.');
          }
          $seen_urls[$normalized_url] = TRUE;
        }
      }

      if (count($errors) !== $before
        || $id === NULL
        || $title === NULL
        || $description === NULL
        || $theme === NULL
        || $type === NULL
        || $language === NULL
        || !is_bool($published)) {
        continue;
      }

      $resource = [
        'id' => $id,
        'title' => $title,
      ];
      if ($url !== NULL) {
        $resource['url'] = $url;
      }
      $resource += [
        'description' => $description,
        'theme' => $theme,
        'type' => $type,
        'language' => $language,
        'published' => $published,
      ];
      foreach ([
        'audience' => $audience,
        'editorial_note' => $editorial_note,
        'last_verified' => $last_verified,
        'order' => $order,
      ] as $key => $value) {
        if ($value !== NULL) {
          $resource[$key] = $value;
        }
      }
      $resources[] = $resource;
    }

    if ($published_count > self::MAX_PUBLISHED_RESOURCES) {
      $this->error($errors, 'resources', 'model_b_required', 'Model A permits at most 20 published resources.');
    }
    if ($published_with_order > 0 && $published_with_order !== $published_count) {
      $this->error($errors, 'resources', 'partial_order', 'Every published resource must define order, or none may define it.');
    }
    if ($errors !== []) {
      return ManifestValidationResult::invalid(
        $errors,
        count($raw_resources),
        $published_count,
        count($observed_themes),
      );
    }

    $published = array_values(array_filter($resources, static fn (array $item): bool => $item['published']));
    $unpublished = array_values(array_filter($resources, static fn (array $item): bool => !$item['published']));
    if ($published_with_order > 0) {
      usort($published, static fn (array $left, array $right): int => ($left['order'] <=> $right['order']) ?: strcmp($left['id'], $right['id']));
    }
    else {
      usort($published, self::fallbackComparator(...));
    }
    usort($unpublished, self::fallbackComparator(...));
    $resources = array_merge($published, $unpublished);

    $themes = [];
    foreach ($published as $resource) {
      if (!in_array($resource['theme'], $themes, TRUE)) {
        $themes[] = $resource['theme'];
      }
    }
    $canonical = [
      'schema_version' => 1,
      'catalogue_approved' => $approved,
      'resources' => $resources,
    ];
    $fingerprint = hash('sha256', json_encode(
      $canonical,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));

    return ManifestValidationResult::valid((bool) $approved, $resources, $published, $themes, $fingerprint);
  }

  /**
   * @param string[] $required
   * @param string[] $allowed
   * @param array<int, array{path: string, code: string, message: string}> $errors
   */
  private function exactKeys(array $data, array $required, array $allowed, string $path, array &$errors): void {
    foreach ($required as $key) {
      if (!array_key_exists($key, $data)) {
        $this->error($errors, $path . '.' . $key, 'required', 'A required field is missing.');
      }
    }
    $unknown = array_diff(array_keys($data), $allowed);
    sort($unknown, SORT_STRING);
    foreach ($unknown as $key) {
      $safe = is_string($key) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) === 1 ? $key : '(invalid-key)';
      $this->error($errors, $path . '.' . $safe, 'unknown_key', 'Unknown fields are not allowed.');
    }
  }

  /** @param array<int, array{path: string, code: string, message: string}> $errors */
  private function id(mixed $value, string $path, array &$errors): ?string {
    if (!is_string($value)
      || strlen($value) > 64
      || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) !== 1) {
      $this->error($errors, $path, 'id_format', 'Use 1–64 lowercase ASCII letters/digits separated by single hyphens.');
      return NULL;
    }
    return $value;
  }

  /** @param array<int, array{path: string, code: string, message: string}> $errors */
  private function text(mixed $value, string $path, int $limit, array &$errors): ?string {
    if (!is_string($value)) {
      $this->error($errors, $path, 'string', 'The field must be a string.');
      return NULL;
    }
    if (preg_match('//u', $value) !== 1) {
      $this->error($errors, $path, 'invalid_utf8', 'Text must be valid UTF-8.');
      return NULL;
    }
    if ($value === '' || preg_match('/^[\s\p{Z}]*$/u', $value) === 1) {
      $this->error($errors, $path, 'empty', 'Text must not be empty.');
      return NULL;
    }
    if (preg_match('/^[\s\p{Z}]|[\s\p{Z}]$/u', $value) === 1) {
      $this->error($errors, $path, 'not_trimmed', 'Text must not have outer whitespace.');
      return NULL;
    }
    if (!\Normalizer::isNormalized($value, \Normalizer::FORM_C)) {
      $this->error($errors, $path, 'not_nfc', 'Text must be Unicode NFC.');
      return NULL;
    }
    if (preg_match('/\p{C}/u', $value) === 1) {
      $this->error($errors, $path, 'control_character', 'Control and format characters are not allowed.');
      return NULL;
    }
    if (str_contains($value, '<') || str_contains($value, '>')) {
      $this->error($errors, $path, 'html', 'Text fields must contain plain text, not HTML.');
      return NULL;
    }
    $length = preg_match_all('/./us', $value);
    if ($length === FALSE || $length > $limit) {
      $this->error($errors, $path, 'length', sprintf('Text exceeds the %d-character limit.', $limit));
      return NULL;
    }
    return $value;
  }

  /** @param array<int, array{path: string, code: string, message: string}> $errors */
  private function optionalText(array $resource, string $key, string $path, array &$errors): ?string {
    return array_key_exists($key, $resource)
      ? $this->text($resource[$key], $path . '.' . $key, self::TEXT_LIMITS[$key], $errors)
      : NULL;
  }

  /** @param array<int, array{path: string, code: string, message: string}> $errors */
  private function language(mixed $value, string $path, array &$errors): ?string {
    if (!is_string($value) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $value) !== 1) {
      $this->error($errors, $path, 'language', 'Language must use xx or xx-YY syntax.');
      return NULL;
    }
    return $value;
  }

  /** @param array<int, array{path: string, code: string, message: string}> $errors */
  private function optionalDate(array $resource, string $path, array &$errors): ?string {
    if (!array_key_exists('last_verified', $resource)) {
      return NULL;
    }
    $value = $resource['last_verified'];
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
      $this->error($errors, $path . '.last_verified', 'date', 'last_verified must use YYYY-MM-DD.');
      return NULL;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $date_errors = \DateTimeImmutable::getLastErrors();
    if ($date === FALSE
      || ($date_errors !== FALSE && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
      || $date->format('Y-m-d') !== $value) {
      $this->error($errors, $path . '.last_verified', 'date', 'last_verified must be a real Gregorian date.');
      return NULL;
    }
    return $value;
  }

  /** @param array<int, array{path: string, code: string, message: string}> $errors */
  private function optionalOrder(array $resource, string $path, array &$errors): ?int {
    if (!array_key_exists('order', $resource)) {
      return NULL;
    }
    $value = $resource['order'];
    if (!is_int($value) || $value < 0 || $value > 9999) {
      $this->error($errors, $path . '.order', 'order', 'order must be an integer from 0 through 9999.');
      return NULL;
    }
    return $value;
  }

  /**
   * Validates one URL without DNS resolution or any external request.
   *
   * @return array{0: ?string, 1: ?string}
   */
  private function url(mixed $value, string $path, array &$errors): array {
    if (!is_string($value)) {
      $this->error($errors, $path, 'string', 'The URL must be a string.');
      return [NULL, NULL];
    }
    if ($value === '' || strlen($value) > self::MAX_URL_BYTES) {
      $this->error($errors, $path, $value === '' ? 'empty' : 'url_length', 'The URL must be non-empty and no longer than 2048 bytes.');
      return [NULL, NULL];
    }
    if (preg_match('//u', $value) !== 1 || !\Normalizer::isNormalized($value, \Normalizer::FORM_C)) {
      $this->error($errors, $path, 'url_unicode', 'The URL must be valid NFC UTF-8.');
      return [NULL, NULL];
    }
    if (preg_match('/[\s\p{Z}\p{C}<>{}"|^`]/u', $value) === 1 || str_contains($value, '\\')) {
      $this->error($errors, $path, 'url_character', 'The URL contains a forbidden character.');
      return [NULL, NULL];
    }
    if (str_starts_with($value, '//')) {
      $this->error($errors, $path, 'protocol_relative', 'Protocol-relative URLs are not allowed.');
      return [NULL, NULL];
    }
    if (str_starts_with($value, '#')) {
      $this->error($errors, $path, 'fragment_only', 'A fragment alone is not an external URL.');
      return [NULL, NULL];
    }
    if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
      $this->error($errors, $path, 'percent_encoding', 'Percent escapes must contain two hexadecimal digits.');
      return [NULL, NULL];
    }

    // PHP parse_url() accepts malformed port suffixes such as :443x. Check the
    // owner-provided authority lexically before using its parsed components.
    if (preg_match('~\Ahttps://([^/?#]*)~iD', $value, $authority_match) !== 1) {
      $this->error($errors, $path, 'https_only', 'Only absolute HTTPS URLs are allowed.');
      return [NULL, NULL];
    }
    $authority = $authority_match[1];
    if ($authority === '') {
      $this->error($errors, $path, 'host_required', 'An absolute URL with a host is required.');
      return [NULL, NULL];
    }
    if (str_contains($authority, '@')) {
      $this->error($errors, $path, 'userinfo', 'Credentials and userinfo are not allowed.');
      return [NULL, NULL];
    }
    if (!str_starts_with($authority, '[') && ($colon = strrpos($authority, ':')) !== FALSE) {
      if (substr($authority, $colon) !== ':443') {
        $this->error($errors, $path, 'port', 'An explicit port must be exactly 443.');
        return [NULL, NULL];
      }
    }

    $parts = parse_url($value);
    if (!is_array($parts)) {
      $this->error($errors, $path, 'malformed_url', 'The URL is malformed.');
      return [NULL, NULL];
    }
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
      $this->error($errors, $path, 'https_only', 'Only absolute HTTPS URLs are allowed.');
      return [NULL, NULL];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      $this->error($errors, $path, 'userinfo', 'Credentials and userinfo are not allowed.');
      return [NULL, NULL];
    }
    if (!isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
      $this->error($errors, $path, 'host_required', 'An absolute URL with a host is required.');
      return [NULL, NULL];
    }
    if (isset($parts['port']) && $parts['port'] !== 443) {
      $this->error($errors, $path, 'port', 'An explicit port must be exactly 443.');
      return [NULL, NULL];
    }
    $host = $this->host($parts['host'], $path, $errors);
    if ($host === NULL) {
      return [NULL, NULL];
    }
    if (isset($parts['path']) && $parts['path'] !== '' && !str_starts_with($parts['path'], '/')) {
      $this->error($errors, $path, 'path', 'The URL path is malformed.');
      return [NULL, NULL];
    }

    foreach (['path', 'query', 'fragment'] as $component) {
      if (!isset($parts[$component])) {
        continue;
      }
      $decoded = $this->decodePercentEscapes($parts[$component]);
      if ($decoded === NULL || preg_match('/[\s\p{Z}\p{C}]/u', $decoded) === 1 || str_contains($decoded, '\\')) {
        $this->error($errors, $path, 'encoded_character', 'Encoded controls, whitespace, backslashes, or excessive nesting are not allowed.');
        return [NULL, NULL];
      }
      $policy_error = $this->sensitiveAssignmentError($decoded, $component === 'query');
      if ($policy_error !== NULL) {
        $message = $policy_error === 'tracking_query'
          ? 'Tracking, referral, campaign, and affiliate parameters are not allowed.'
          : ($policy_error === 'credential_query'
            ? 'Credential-bearing parameters are not allowed.'
            : 'Query keys must be non-empty visible NFC text.');
        $this->error($errors, $path, $policy_error, $message);
        return [NULL, NULL];
      }
    }

    $normalized = 'https://' . $host . $this->normalizePath($parts['path'] ?? '/');
    if (isset($parts['query'])) {
      $normalized .= '?' . $this->normalizeComponent($parts['query']);
    }
    if (isset($parts['fragment'])) {
      $normalized .= '#' . $this->normalizeComponent($parts['fragment']);
    }
    return [$value, $normalized];
  }

  /** @param array<int, array{path: string, code: string, message: string}> $errors */
  private function host(string $host, string $path, array &$errors): ?string {
    if (str_starts_with($host, '[')
      || str_ends_with($host, ']')
      || filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      $this->error($errors, $path, 'ip_literal', 'IP-literal destinations are not allowed.');
      return NULL;
    }
    if (str_contains($host, '%')
      || preg_match('/[.\x{3002}\x{FF0E}\x{FF61}]$/u', $host) === 1) {
      $this->error($errors, $path, 'malformed_host', 'Encoded or trailing-dot hostnames are not allowed.');
      return NULL;
    }
    if (!defined('IDNA_NONTRANSITIONAL_TO_ASCII')
      || !defined('IDNA_USE_STD3_RULES')
      || !defined('INTL_IDNA_VARIANT_UTS46')) {
      $this->error($errors, 'runtime', 'idna_runtime', 'UTS #46 IDNA support is unavailable.');
      return NULL;
    }
    $info = [];
    $ascii = idn_to_ascii(
      $host,
      IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES,
      INTL_IDNA_VARIANT_UTS46,
      $info,
    );
    if (!is_string($ascii) || $ascii === '' || (int) ($info['errors'] ?? 1) !== 0) {
      $this->error($errors, $path, 'idna', 'The hostname is malformed or ambiguous.');
      return NULL;
    }
    $ascii = strtolower($ascii);
    if ($this->allowExampleInvalid && $ascii === 'example.invalid') {
      return $ascii;
    }
    foreach (self::RESERVED_SUFFIXES as $suffix) {
      if ($ascii === $suffix || str_ends_with($ascii, '.' . $suffix)) {
        $this->error($errors, $path, 'reserved_host', 'Local and reserved hostnames are not allowed.');
        return NULL;
      }
    }
    if (strlen($ascii) > 253 || !str_contains($ascii, '.')) {
      $this->error($errors, $path, 'public_host', 'The hostname must be a dotted, non-reserved name.');
      return NULL;
    }
    foreach (explode('.', $ascii) as $label) {
      if ($label === '' || strlen($label) > 63 || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/D', $label) !== 1) {
        $this->error($errors, $path, 'hostname_label', 'The hostname contains an invalid DNS label.');
        return NULL;
      }
    }
    if (filter_var($ascii, FILTER_VALIDATE_IP) !== FALSE
      || preg_match('/^[0-9.]+$/D', $ascii) === 1
      || preg_match('/^(?:(?:0x[0-9a-f]+|[0-9]+)\.)+(?:0x[0-9a-f]+|[0-9]+)$/Di', $ascii) === 1) {
      $this->error($errors, $path, 'ip_literal', 'IP-literal destinations are not allowed.');
      return NULL;
    }
    foreach (['example.com', 'example.net', 'example.org'] as $reserved) {
      if ($ascii === $reserved || str_ends_with($ascii, '.' . $reserved)) {
        $this->error($errors, $path, 'reserved_host', 'Documentation hostnames are not production destinations.');
        return NULL;
      }
    }
    return $ascii;
  }

  /**
   * Checks direct query keys and nested assignments in decoded URL data.
   */
  private function sensitiveAssignmentError(string $decoded, bool $is_query): ?string {
    if ($is_query) {
      foreach (preg_split('/[&;]/D', $decoded) ?: [] as $pair) {
        $key = explode('=', $pair, 2)[0];
        $error = $this->queryKeyError(str_replace('+', ' ', $key));
        if ($error !== NULL) {
          return $error;
        }
      }
    }
    preg_match_all(
      '/(?<![\p{L}\p{N}_.\-\[\]])([\p{L}\p{N}_.\-\[\]]{1,2048})=/u',
      $decoded,
      $matches,
    );
    foreach ($matches[1] ?? [] as $key) {
      $error = $this->queryKeyError($key);
      if ($error !== NULL) {
        return $error;
      }
    }
    return NULL;
  }

  private function queryKeyError(string $key): ?string {
    if ($key === ''
      || preg_match('/[\s\p{Z}\p{C}]/u', $key) === 1
      || !\Normalizer::isNormalized($key, \Normalizer::FORM_C)) {
      return 'query_key';
    }
    $snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key) ?? $key;
    $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $snake) ?? $snake;
    $snake = strtolower(str_replace(['-', '.', '[', ']'], '_', $snake));
    $snake = preg_replace('/_+/', '_', $snake) ?? $snake;
    if (preg_match('/(?:^|_)utm_[a-z0-9]+(?:$|_)/D', $snake) === 1
      || self::hasBoundedAlias($snake, [...self::TRACKING_KEYS, 'utmsource', 'utmmedium', 'utmcampaign', 'utmterm', 'utmcontent', 'utmid'])) {
      return 'tracking_query';
    }
    if (self::hasBoundedAlias($snake, self::CREDENTIAL_KEYS)) {
      return 'credential_query';
    }
    return NULL;
  }

  /** @param string[] $keys */
  private static function hasBoundedAlias(string $snake, array $keys): bool {
    $aliases = [];
    foreach ($keys as $key) {
      $key = trim($key, '_');
      $aliases[] = $key;
      $aliases[] = str_replace('_', '', $key);
    }
    $pattern = implode('|', array_map(
      static fn (string $item): string => preg_quote($item, '/'),
      array_values(array_unique($aliases)),
    ));
    return preg_match('/(?:^|_)(?:' . $pattern . ')(?:$|_)/D', $snake) === 1;
  }

  /**
   * Decodes percent escapes to a fixed point with a strict work bound.
   */
  private function decodePercentEscapes(string $value): ?string {
    $decoded = $value;
    for ($round = 0; $round < 5; $round++) {
      if (preg_match('//u', $decoded) !== 1 || preg_match('/\p{C}/u', $decoded) === 1) {
        return NULL;
      }
      if (preg_match('/%[0-9A-Fa-f]{2}/', $decoded) !== 1) {
        return $decoded;
      }
      $next = rawurldecode($decoded);
      if ($next === $decoded) {
        return $decoded;
      }
      $decoded = $next;
    }
    return preg_match('/%[0-9A-Fa-f]{2}/', $decoded) === 1 ? NULL : $decoded;
  }

  /**
   * Normalizes unreserved escapes and percent-encoded UTF-8 for comparison.
   */
  private function normalizeComponent(string $component): string {
    $normalized = '';
    $length = strlen($component);
    for ($offset = 0; $offset < $length;) {
      if ($component[$offset] !== '%') {
        $normalized .= $component[$offset++];
        continue;
      }

      $byte = hexdec(substr($component, $offset + 1, 2));
      $character = chr((int) $byte);
      if ($byte < 0x80) {
        $normalized .= preg_match('/^[A-Za-z0-9._~-]$/D', $character) === 1
          ? $character
          : sprintf('%%%02X', $byte);
        $offset += 3;
        continue;
      }

      $sequence_length = match (TRUE) {
        $byte >= 0xC2 && $byte <= 0xDF => 2,
        $byte >= 0xE0 && $byte <= 0xEF => 3,
        $byte >= 0xF0 && $byte <= 0xF4 => 4,
        default => 0,
      };
      $sequence = $character;
      $valid_sequence = $sequence_length > 0;
      for ($index = 1; $valid_sequence && $index < $sequence_length; $index++) {
        $escape_offset = $offset + ($index * 3);
        if ($escape_offset + 2 >= $length
          || $component[$escape_offset] !== '%'
          || preg_match('/^[0-9A-Fa-f]{2}$/D', substr($component, $escape_offset + 1, 2)) !== 1) {
          $valid_sequence = FALSE;
          break;
        }
        $continuation = hexdec(substr($component, $escape_offset + 1, 2));
        if ($continuation < 0x80 || $continuation > 0xBF) {
          $valid_sequence = FALSE;
          break;
        }
        $sequence .= chr((int) $continuation);
      }
      if ($valid_sequence && preg_match('//u', $sequence) === 1) {
        $normalized .= $sequence;
        $offset += $sequence_length * 3;
        continue;
      }

      $normalized .= sprintf('%%%02X', $byte);
      $offset += 3;
    }
    return \Normalizer::normalize($normalized, \Normalizer::FORM_C) ?: $normalized;
  }

  private function normalizePath(string $path): string {
    $path = $this->normalizeComponent($path === '' ? '/' : $path);
    $directory = preg_match('~(?:^|/)(?:\.|\.\.)$~D', $path) === 1;
    $output = [];
    foreach (explode('/', $path) as $segment) {
      if ($segment === '' && $output === []) {
        $output[] = '';
        continue;
      }
      if ($segment === '.') {
        continue;
      }
      if ($segment === '..') {
        if (count($output) > 1) {
          array_pop($output);
        }
        continue;
      }
      $output[] = $segment;
    }
    $normalized = implode('/', $output);
    if ($normalized === '') {
      return '/';
    }
    $normalized = str_starts_with($normalized, '/') ? $normalized : '/' . $normalized;
    return $directory ? $normalized . '/' : $normalized;
  }

  private static function fallbackComparator(array $left, array $right): int {
    foreach (['theme', 'title', 'id'] as $key) {
      $comparison = strcmp($left[$key], $right[$key]);
      if ($comparison !== 0) {
        return $comparison;
      }
    }
    return 0;
  }

  /** @param array<int, array{path: string, code: string, message: string}> $errors */
  private function error(array &$errors, string $path, string $code, string $message): void {
    $errors[] = ['path' => $path, 'code' => $code, 'message' => $message];
  }

}
