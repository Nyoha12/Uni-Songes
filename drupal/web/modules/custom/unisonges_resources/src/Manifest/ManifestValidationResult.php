<?php

declare(strict_types=1);

namespace Drupal\unisonges_resources\Manifest;

/**
 * Immutable, fail-closed result of one complete manifest validation.
 */
final readonly class ManifestValidationResult {

  /**
   * @param array<int, array{path: string, code: string, message: string}> $errors
   * @param array<int, array<string, mixed>> $resources
   * @param array<int, array<string, mixed>> $publishedResources
   * @param string[] $themes
   */
  private function __construct(
    private bool $valid,
    private bool $catalogueApproved,
    private array $errors,
    private array $resources,
    private array $publishedResources,
    private array $themes,
    private string $fingerprint,
    private int $observedTotalCount,
    private int $observedPublishedCount,
    private int $observedThemeCount,
  ) {}

  /**
   * @param array<int, array<string, mixed>> $resources
   * @param array<int, array<string, mixed>> $published_resources
   * @param string[] $themes
   */
  public static function valid(
    bool $catalogue_approved,
    array $resources,
    array $published_resources,
    array $themes,
    string $fingerprint,
  ): self {
    return new self(
      TRUE,
      $catalogue_approved,
      [],
      $resources,
      $published_resources,
      $themes,
      $fingerprint,
      count($resources),
      count($published_resources),
      count($themes),
    );
  }

  /**
   * @param array<int, array{path: string, code: string, message: string}> $errors
   */
  public static function invalid(
    array $errors,
    int $observed_total = 0,
    int $observed_published = 0,
    int $observed_themes = 0,
  ): self {
    return new self(
      FALSE,
      FALSE,
      $errors,
      [],
      [],
      [],
      '',
      max(0, $observed_total),
      max(0, $observed_published),
      max(0, $observed_themes),
    );
  }

  public function isValid(): bool {
    return $this->valid;
  }

  public function isCatalogueApproved(): bool {
    return $this->valid && $this->catalogueApproved;
  }

  /** @return array<int, array{path: string, code: string, message: string}> */
  public function errors(): array {
    return $this->errors;
  }

  /** @return string[] */
  public function errorCodes(): array {
    return array_column($this->errors, 'code');
  }

  /** @return array<int, array<string, mixed>> */
  public function resources(): array {
    return $this->resources;
  }

  /** @return array<int, array<string, mixed>> */
  public function publishedResources(): array {
    return $this->publishedResources;
  }

  /**
   * Returns only the public allowlist for one exact validated theme.
   *
   * @return array<int, array<string, mixed>>
   */
  public function publicResourcesForTheme(string $theme): array {
    if (!$this->hasTheme($theme)) {
      return [];
    }

    $public = [];
    foreach ($this->publishedResources as $resource) {
      if ($resource['theme'] !== $theme) {
        continue;
      }
      $public[] = array_intersect_key($resource, array_flip([
        'title',
        'url',
        'description',
        'theme',
        'type',
        'language',
        'audience',
        'last_verified',
      ]));
    }
    return $public;
  }

  public function hasTheme(string $theme): bool {
    return $this->valid && in_array($theme, $this->themes, TRUE);
  }

  /** @return string[] */
  public function themes(): array {
    return $this->themes;
  }

  public function fingerprint(): string {
    return $this->fingerprint;
  }

  public function totalCount(): int {
    return $this->observedTotalCount;
  }

  public function publishedCount(): int {
    return $this->observedPublishedCount;
  }

  public function themeCount(): int {
    return $this->observedThemeCount;
  }

}
