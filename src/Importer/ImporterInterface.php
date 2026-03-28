<?php

namespace Drupal\git_content\Importer;

/**
 * Interface for pluggable per-entity-type importers.
 *
 * Implement this interface (by extending BaseImporter) and tag the service
 * with 'git_content.importer' to register it with MarkdownImporter's registry.
 *
 * Each importer declares its entity type via getEntityType(). Return NULL
 * for the catch-all importer (NodeImporter) which handles any type not
 * claimed by a specific importer.
 */
interface ImporterInterface {

  /**
   * The Drupal entity type machine name handled by this importer.
   *
   * Used by MarkdownImporter to build the entity-type registry automatically.
   * Return NULL for the catch-all importer (NodeImporter) which handles all
   * entity types not claimed by a specific importer.
   */
  public function getEntityType(): ?string;

  /**
   * Import priority weight (lower = imported first).
   *
   * Controls the order entities are imported to satisfy dependencies.
   * E.g., files (10) before media (40), media before nodes (60).
   */
  public function getImportWeight(): int;

  /**
   * Extract the entity's primary ID from frontmatter.
   *
   * @return int|null
   *   The entity ID, or NULL if not present in the frontmatter.
   */
  public function extractEntityId(array $frontmatter): ?int;

  /**
   * Resolve the bundle from frontmatter, or NULL if not applicable.
   *
   * Used by MarkdownImporter for import tracking and deletion sync.
   */
  public function resolveBundle(array $frontmatter): ?string;

  /**
   * The entity storage field name used for bundle queries in deletion sync.
   *
   * E.g. 'type' for node, 'vid' for taxonomy_term, 'bundle' for media.
   * Return NULL if deletion sync should not filter by bundle.
   */
  public function getBundleQueryField(): ?string;

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
