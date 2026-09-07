<?php

declare(strict_types=1);

namespace Drupal\unisonges_resources\Manifest;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads, strictly parses, validates, and request-memoizes one manifest.
 */
final class ManifestRepository {

  private const MAX_BYTES = 1048576;

  private ?ManifestValidationResult $memoized = NULL;

  public function __construct(
    private readonly string $manifestPath,
    private readonly ManifestValidator $validator,
  ) {}

  /**
   * Creates the only repository variant allowed to accept example.invalid.
   */
  public static function forTestFixtures(string $manifest_path = ''): self {
    return new self($manifest_path, ManifestValidator::forTestFixtures());
  }

  public function load(): ManifestValidationResult {
    if ($this->memoized !== NULL) {
      return $this->memoized;
    }
    if (!is_file($this->manifestPath) || !is_readable($this->manifestPath)) {
      return $this->memoized = $this->invalid('file_unreadable', 'The manifest file is missing or unreadable.');
    }
    $size = filesize($this->manifestPath);
    if ($size === FALSE || $size > self::MAX_BYTES) {
      return $this->memoized = $this->invalid('file_size', 'The manifest exceeds one megabyte.');
    }
    $yaml = file_get_contents($this->manifestPath);
    if (!is_string($yaml)) {
      return $this->memoized = $this->invalid('file_read', 'The manifest could not be read.');
    }
    return $this->memoized = $this->validateYaml($yaml);
  }

  /**
   * Strict parsing seam used by the data-driven static test matrix.
   */
  public function validateYaml(string $yaml): ManifestValidationResult {
    if (strlen($yaml) > self::MAX_BYTES) {
      return $this->invalid('file_size', 'The manifest exceeds one megabyte.');
    }
    if (preg_match('//u', $yaml) !== 1) {
      return $this->invalid('invalid_utf8', 'The manifest must be valid UTF-8.');
    }
    if (str_contains($yaml, "\0")) {
      return $this->invalid('nul_byte', 'The manifest must not contain NUL bytes.');
    }
    if ($this->containsYamlIndirection($yaml)) {
      return $this->invalid('yaml_indirection', 'YAML anchors, aliases, and merge keys are not allowed.');
    }
    try {
      $parsed = Yaml::parse(
        $yaml,
        Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_OBJECT_FOR_MAP,
      );
    }
    catch (ParseException $exception) {
      $code = str_contains(strtolower($exception->getMessage()), 'duplicate key')
        ? 'yaml_duplicate_key'
        : 'yaml_parse';
      return $this->invalid($code, 'The manifest YAML is malformed.');
    }
    catch (\Throwable) {
      return $this->invalid('yaml_parse', 'The manifest YAML could not be parsed safely.');
    }
    return $this->validator->validate($parsed);
  }

  public function reset(): void {
    $this->memoized = NULL;
  }

  private function invalid(string $code, string $message): ManifestValidationResult {
    return ManifestValidationResult::invalid([[
      'path' => 'manifest',
      'code' => $code,
      'message' => $message,
    ]]);
  }

  /**
   * Detects YAML indirection tokens outside quotes, comments, and block text.
   */
  private function containsYamlIndirection(string $yaml): bool {
    $block_indent = NULL;
    foreach (preg_split('/\R/u', $yaml) ?: [] as $line) {
      $trimmed = trim($line);
      $indent = strlen($line) - strlen(ltrim($line, ' '));
      if ($block_indent !== NULL) {
        if ($trimmed === '' || $indent > $block_indent) {
          continue;
        }
        $block_indent = NULL;
      }
      $structural = $this->stripQuotedAndCommentText($line);
      if (preg_match('/(?:^|[\s{,\-])<<\s*:/', $structural) === 1
        || preg_match('/(?:^|[\s\[\]{},:\-])[&*][^\s\[\]{},]+/', $structural) === 1) {
        return TRUE;
      }
      if (preg_match('/(?:^|:)\s*[|>][0-9+\-]*\s*$/', $structural) === 1) {
        $block_indent = $indent;
      }
    }
    return FALSE;
  }

  private function stripQuotedAndCommentText(string $line): string {
    $output = '';
    $quote = NULL;
    $escaped = FALSE;
    for ($index = 0, $length = strlen($line); $index < $length; $index++) {
      $character = $line[$index];
      if ($quote === '"') {
        $output .= ' ';
        if ($escaped) {
          $escaped = FALSE;
        }
        elseif ($character === '\\') {
          $escaped = TRUE;
        }
        elseif ($character === '"') {
          $quote = NULL;
        }
        continue;
      }
      if ($quote === "'") {
        $output .= ' ';
        if ($character === "'" && $index + 1 < $length && $line[$index + 1] === "'") {
          $output .= ' ';
          $index++;
        }
        elseif ($character === "'") {
          $quote = NULL;
        }
        continue;
      }
      if ($character === '"' || $character === "'") {
        $quote = $character;
        $output .= ' ';
        continue;
      }
      if ($character === '#' && ($index === 0 || ctype_space($line[$index - 1]))) {
        break;
      }
      $output .= $character;
    }
    return $output;
  }

}
