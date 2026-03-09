<?php

namespace Drupal\git_content\Exporter;

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
  public function exportAll(): array {
    $result = ['exported' => [], 'skipped' => [], 'deleted' => [], 'errors' => []];

    $exporters = [
      'node'              => $this->nodeExporter,
      'taxonomy_term'     => $this->taxonomyExporter,
      'media'             => $this->mediaExporter,
      'block_content'     => $this->blockContentExporter,
      'file'              => $this->fileExporter,
      'user'              => $this->userExporter,
      'menu_link_content' => $this->menuLinkExporter,
    ];

    $touchedFiles = [];
    $typeCounts   = [];

    foreach ($exporters as $entity_type => $exporter) {
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
      $path = DRUPAL_ROOT . '/content_export/' . $file;
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
  // Per-type export
  // ---------------------------------------------------------------------------

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
        $relpath    = str_replace(DRUPAL_ROOT . '/content_export/', '', $filepath);
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
    $dir = DRUPAL_ROOT . '/content_export';
    if (!is_dir($dir)) {
      return [];
    }

    $files = [];
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
      if ($file->isFile() && $file->getExtension() === 'md') {
        $files[] = str_replace(DRUPAL_ROOT . '/content_export/', '', $file->getPathname());
      }
    }

    sort($files);
    return $files;
  }

}
