<?php

namespace Drupal\git_content\Exporter;

use Drupal\git_content\Utility\ContentExportTrait;
use Drupal\git_content\Utility\SummaryTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the export of all Drupal content to content_export/.
 *
 * Iterates every entity type, delegates to the appropriate concrete exporter,
 * detects orphaned .md files left over from previous runs and removes them.
 * Mirrors MarkdownImporter::importAll() on the export side.
 */
class MarkdownExporter {

  use ContentExportTrait;
  use SummaryTrait;

  protected LoggerInterface $logger;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected NodeExporter $nodeExporter,
    protected TaxonomyExporter $taxonomyExporter,
    protected MediaExporter $mediaExporter,
    protected FileExporter $fileExporter,
    protected UserExporter $userExporter,
    protected BlockContentExporter $blockContentExporter,
    protected MenuLinkExporter $menuLinkExporter,
  ) {
    $this->logger = $loggerFactory->get('git_content');
  }

  // ---------------------------------------------------------------------------
  // Bulk export
  // ---------------------------------------------------------------------------

  /**
   * Export all content to content_export/.
   *
   * @return array{exported: string[], skipped: string[], deleted: string[], errors: string[]}
   *   Relative paths of files written, unchanged, removed, and any errors.
   */
  /**
   * Export a subset of entity types without orphan cleanup.
   *
   * Useful for partial exports (e.g. "only nodes"). For a full content sync
   * including removal of stale files, use exportAll() instead.
   *
   * @param string[] $entityTypes
   *   Entity type machine names, e.g. ['node', 'taxonomy_term'].
   *
   * @return array{exported: string[], skipped: string[], deleted: string[], errors: string[]}
   */
  /**
   * Return per-type entity counts and file counts without writing anything.
   *
   * @return array<string, array{entities: int, files: int}>
   *   Keyed by entity type machine name.
   */
  public function previewAll(): array {
    $dirMap = [
      'node'              => 'content_types',
      'taxonomy_term'     => 'taxonomy',
      'media'             => 'media',
      'block_content'     => 'blocks',
      'file'              => 'files',
      'user'              => 'users',
      'menu_link_content' => 'menus',
    ];

    // Count existing .md files per top-level directory once.
    $fileCounts = [];
    foreach ($this->scanContentExportFiles() as $relpath) {
      $dir = explode('/', $relpath)[0] ?? '';
      $fileCounts[$dir] = ($fileCounts[$dir] ?? 0) + 1;
    }

    $preview = [];
    foreach ($this->exporterMap() as $entity_type => $exporter) {
      try {
        $ids = $this->entityTypeManager->getStorage($entity_type)
          ->getQuery()
          ->accessCheck(TRUE)
          ->execute();
        $entities = count($ids);
      }
      catch (\Exception $e) {
        $entities = 0;
      }

      $dir                   = $dirMap[$entity_type] ?? $entity_type;
      $preview[$entity_type] = ['entities' => $entities, 'files' => $fileCounts[$dir] ?? 0];
    }

    return $preview;
  }

  public function exportTypes(array $entityTypes): array {
    $result = ['exported' => [], 'skipped' => [], 'deleted' => [], 'errors' => []];

    $map = array_intersect_key($this->exporterMap(), array_flip($entityTypes));
    foreach ($map as $entity_type => $exporter) {
      $typeResult = $this->exportEntityType($entity_type, $exporter);
      array_push($result['exported'], ...$typeResult['exported_files']);
      array_push($result['skipped'], ...$typeResult['skipped_files']);
      array_push($result['errors'], ...$typeResult['errors']);
    }

    return $result;
  }

  public function exportAll(): array {
    $result = ['exported' => [], 'skipped' => [], 'deleted' => [], 'errors' => []];

    $touchedFiles = [];
    $typeCounts   = [];

    foreach ($this->exporterMap() as $entity_type => $exporter) {
      $typeResult = $this->exportEntityType($entity_type, $exporter);

      $exp = count($typeResult['exported_files']);
      $skp = count($typeResult['skipped_files']);
      $typeCounts[$entity_type] = ['exported' => $exp, 'skipped' => $skp];

      foreach ($typeResult['exported_files'] as $file) {
        $result['exported'][] = $file;
        $touchedFiles[] = $file;
      }
      foreach ($typeResult['skipped_files'] as $file) {
        $result['skipped'][] = $file;
        $touchedFiles[] = $file;
      }
      foreach ($typeResult['errors'] as $error) {
        $result['errors'][] = $error;
      }
    }

    // Remove .md files that are on disk but were not touched in this run
    // (entities deleted from Drupal since the last export).
    $allFiles = $this->scanContentExportFiles();
    $orphans  = array_diff($allFiles, $touchedFiles);
    foreach ($orphans as $file) {
      $result['deleted'][] = $file;
      $path = $this->contentExportDir() . '/' . $file;
      if (is_file($path)) {
        @unlink($path);
      }
    }

    $this->logger->notice(
      'Export finished: @summary. Total: @exported exported, @skipped unchanged, @deleted deleted, @errors errors.',
      [
        '@summary'  => $this->buildTypeSummary($typeCounts),
        '@exported' => (string) count($result['exported']),
        '@skipped'  => (string) count($result['skipped']),
        '@deleted'  => (string) count($result['deleted']),
        '@errors'   => (string) count($result['errors']),
      ]
    );

    return $result;
  }

  // ---------------------------------------------------------------------------
  // Internals
  // ---------------------------------------------------------------------------

  /**
   * Returns the entity-type → exporter map used by exportAll() and exportTypes().
   *
   * @return array<string, BaseExporter>
   */
  private function exporterMap(): array {
    return [
      'node'              => $this->nodeExporter,
      'taxonomy_term'     => $this->taxonomyExporter,
      'media'             => $this->mediaExporter,
      'block_content'     => $this->blockContentExporter,
      'file'              => $this->fileExporter,
      'user'              => $this->userExporter,
      'menu_link_content' => $this->menuLinkExporter,
    ];
  }

  /**
   * Export all entities of a single type using the given exporter.
   *
   * @return array{exported_files: string[], skipped_files: string[], errors: string[]}
   */
  private function exportEntityType(string $entity_type, BaseExporter $exporter): array {
    $exportedFiles = [];
    $skippedFiles  = [];
    $errors        = [];

    $storage = $this->entityTypeManager->getStorage($entity_type);

    // Some entity types may not be installed (e.g. media, block_content).
    try {
      $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
    }
    catch (\Exception $e) {
      return ['exported_files' => [], 'skipped_files' => [], 'errors' => []];
    }

    if (empty($ids)) {
      return ['exported_files' => [], 'skipped_files' => [], 'errors' => []];
    }

    foreach ($storage->loadMultiple($ids) as $entity) {
      try {
        $fileResult = $exporter->exportToFile($entity);
        $filepath   = is_array($fileResult) ? $fileResult['path'] : $fileResult;
        $relpath    = str_replace($this->contentExportDir() . '/', '', $filepath);
        $skipped    = is_array($fileResult) ? ($fileResult['skipped'] ?? FALSE) : FALSE;

        if ($skipped) {
          $skippedFiles[] = $relpath;
        }
        else {
          $exportedFiles[] = $relpath;
        }
      }
      catch (\Exception $e) {
        $errors[] = $entity_type . ':' . $entity->id() . ': ' . $e->getMessage();
        $this->logger->error(
          'Failed to export @type @id: @message',
          ['@type' => $entity_type, '@id' => (string) $entity->id(), '@message' => $e->getMessage()]
        );
      }
    }

    return ['exported_files' => $exportedFiles, 'skipped_files' => $skippedFiles, 'errors' => $errors];
  }

  // ---------------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------------

  /**
   * Return all .md files currently on disk under content_export/, relative paths.
   *
   * @return string[]
   */
  private function scanContentExportFiles(): array {
    $base  = $this->contentExportDir() . '/';
    $files = array_map(fn($p) => str_replace($base, '', $p), $this->scanMarkdownFiles());
    sort($files);
    return $files;
  }

}
