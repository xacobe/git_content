<?php

namespace Drupal\git_content\Utility;

/**
 * Provides a canonical, deterministic hash helper for change detection.
 *
 * Used by both BaseExporter (to write the checksum into frontmatter) and
 * MarkdownImporter (to verify the checksum on import).
 */
trait ChecksumTrait {

  /**
   * Compute the canonical SHA1 checksum for a flattened frontmatter + body.
   *
   * The frontmatter must already be flattened (groups expanded) and must NOT
   * contain the 'checksum' key itself. Keys matching /^_+$/ (separator lines)
   * are stripped before hashing.
   */
  protected function computeChecksum(array $frontmatter, string $body): string {
    $fm = $frontmatter;
    unset($fm['checksum']);
    $data = $this->canonicalizeForHash(['frontmatter' => $fm, 'body' => $body]);

    return sha1(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
  }

  /**
   * Canonicalize a data structure for hashing.
   *
   * Recursively sorts array keys (associative) or values (sequential scalars)
   * so the SHA1 hash is deterministic regardless of YAML/JSON key order or
   * serialization details.
   */
  protected function canonicalizeForHash(mixed $data): mixed {
    if (is_array($data)) {
      $keys = array_keys($data);
      $is_sequential = $keys === range(0, count($data) - 1);

      if ($is_sequential) {
        $data = array_map(fn($item) => $this->canonicalizeForHash($item), $data);

        // For scalar values, sort so order changes don't affect the checksum.
        $all_scalars = array_reduce($data, fn($carry, $item) => $carry && (is_null($item) || is_scalar($item)), TRUE);
        if ($all_scalars) {
          sort($data);
        }
        else {
          // For arrays of objects/arrays, sort by their JSON representation.
          usort($data, fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
        }

        return $data;
      }

      // Associative: sort keys and canonicalize recursively.
      ksort($data);
      foreach ($data as $key => $value) {
        $data[$key] = $this->canonicalizeForHash($value);
      }
    }
    return $data;
  }

}
