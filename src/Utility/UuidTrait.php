<?php

namespace Drupal\git_content\Utility;

/**
 * UUID shortening and expansion helpers shared across exporters, importers,
 * and field handlers.
 *
 * The "short UUID" is the first 8 characters of the full UUID with hyphens
 * stripped (e.g. 'a1b2c3d4' from 'a1b2c3d4-e5f6-7890-ab12-cdef01234567').
 * It is used in frontmatter for human readability while preserving
 * traceability to the full UUID on import.
 */
trait UuidTrait {

  /**
   * Shorten a UUID to 8 characters for readability in frontmatter.
   */
  protected function shortenUuid(string $uuid): string {
    return substr(str_replace('-', '', $uuid), 0, 8);
  }

  /**
   * Reconstruct a full UUID from a short UUID (8 chars).
   *
   * If the input is already a full UUID (32 chars without dashes) it is
   * formatted with dashes. Otherwise the 8-char prefix is padded with zeros
   * to produce a deterministic UUID that starts with those characters.
   */
  protected function expandShortUuid(string $short): string {
    $clean = str_replace('-', '', $short);

    // Already a full UUID without dashes (32 chars): format with dashes.
    if (strlen($clean) === 32) {
      return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($clean, 4));
    }

    // Short UUID: pad with zeros and format as a valid UUID.
    $padded = str_pad($clean, 32, '0');
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($padded, 4));
  }

}
