<?php

namespace Drupal\git_content\Utility;

/**
 * Provides a shared helper to format per-type operation summaries for logging.
 *
 * Used by MarkdownExporter and MarkdownImporter to produce a consistent
 * watchdog message: "node: 5 exported, 2 skipped; taxonomy_term: 1 exported"
 */
trait SummaryTrait {

  /**
   * Build a per-type summary string from an operation count map.
   *
   * @param array<string, array<string, int>> $typeCounts
   *   Keyed by type, each value is an array of op => count.
   *   Example: ['node' => ['exported' => 5, 'skipped' => 2], ...]
   *
   * @return string
   *   Human-readable summary, e.g.
   *   "node: 5 exported, 2 skipped; taxonomy_term: 0 exported, 1 skipped"
   */
  protected function buildTypeSummary(array $typeCounts): string {
    $parts = [];
    foreach ($typeCounts as $type => $counts) {
      $items = [];
      foreach ($counts as $op => $count) {
        $items[] = "$count $op";
      }
      $parts[] = "$type: " . implode(', ', $items);
    }
    return implode('; ', $parts);
  }

}
