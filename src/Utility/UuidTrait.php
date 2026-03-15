<?php

namespace Drupal\git_content\Utility;

/**
 * UUID helpers shared across exporters, importers, and field handlers.
 *
 * Full UUIDs are stored in frontmatter for portability across environments.
 * expandShortUuid() handles both legacy 8-char short UUIDs (from old .md files)
 * and full 36-char UUIDs transparently.
 */
trait UuidTrait {

  /**
   * Reconstruct a full UUID from a short UUID (8 chars) or pass through a full UUID.
   *
   * - Full UUID (36 chars with dashes): returned as-is after normalizing dashes.
   * - Legacy short UUID (8 hex chars): padded with zeros to produce a deterministic UUID.
   */
  protected function expandShortUuid(string $short): string {
    $clean = str_replace('-', '', $short);

    // Already a full UUID without dashes (32 chars): format with dashes.
    if (strlen($clean) === 32) {
      return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($clean, 4));
    }

    // Legacy short UUID: pad with zeros and format as a valid UUID.
    $padded = str_pad($clean, 32, '0');
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($padded, 4));
  }

}
