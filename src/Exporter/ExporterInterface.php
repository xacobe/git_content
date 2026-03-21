<?php

namespace Drupal\git_content\Exporter;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for pluggable per-entity-type exporters.
 *
 * Implement this interface (by extending BaseExporter) and tag the service
 * with 'git_content.exporter' to register it with MarkdownExporter's registry.
 */
interface ExporterInterface {

  /**
   * The Drupal entity type machine name handled by this exporter.
   *
   * Used by MarkdownExporter to build the entity-type → exporter map.
   * Must match the entity type ID used in EntityTypeManagerInterface::getStorage().
   */
  public function getEntityType(): string;

  /**
   * Generate the full Markdown contents for the entity.
   *
   * @return string
   *   The .md file contents.
   */
  public function export(EntityInterface $entity): string;

  /**
   * Export the entity to a Markdown file on disk.
   *
   * @return array{path: string, skipped: bool}
   *   Generated file information.
   */
  public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array;

}
