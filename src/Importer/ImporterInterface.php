<?php

namespace Drupal\git_content\Importer;

/**
 * Interface for pluggable per-entity-type importers.
 *
 * Implement this interface (by extending BaseImporter) and tag the service
 * with 'git_content.importer' to register it with MarkdownImporter's registry.
 *
 * Tag priority controls dispatch order. The catch-all (NodeImporter) should
 * be tagged with priority: -100 so specific importers are checked first.
 */
interface ImporterInterface {

  /**
   * Whether this importer handles the given entity type.
   *
   * @param string $entity_type
   *   The resolved entity type machine name (e.g. 'node', 'taxonomy_term').
   */
  public function handles(string $entity_type): bool;

  /**
   * Import or update a single entity from its parsed frontmatter and body.
   *
   * @param array $frontmatter
   *   Flattened frontmatter data from the .md file.
   * @param string $body
   *   Markdown body content.
   *
   * @return string
   *   'imported' for new entities, 'updated' for existing ones.
   *
   * @throws \Exception
   */
  public function import(array $frontmatter, string $body): string;

}
