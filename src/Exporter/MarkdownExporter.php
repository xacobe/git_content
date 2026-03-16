<?php

namespace Drupal\git_content\Exporter;

use Drupal\git_content\Utility\ContentExportTrait;
use Drupal\git_content\Utility\SummaryTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
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
   * Return per-type entity counts and file counts without writing anything.
   *
   * @return array<string, array{entities: int, files: int}>
   *   Keyed by entity type machine name.
   */
  /**
   * Preview what exportAll() would do without writing anything.
   *
   * Runs the full export pipeline in dry-run mode (same cost as exportAll
   * minus actual disk writes) and returns the same array shape so callers
   * can treat preview and real results identically.
   *
   * @return array{exported: string[], skipped: string[], deleted: string[], errors: string[]}
   */
  public function previewAll(): array {
    $result       = ['exported' => [], 'skipped' => [], 'deleted' => [], 'errors' => []];
    $touchedFiles = [];

    foreach ($this->exporterMap() as $entity_type => $exporter) {
      $typeResult = $this->exportEntityType($entity_type, $exporter, TRUE);

      foreach ($typeResult['exported_files'] as $file) {
        $result['exported'][] = $file;
        $touchedFiles[]       = $file;
      }
      foreach ($typeResult['skipped_files'] as $file) {
        $result['skipped'][] = $file;
        $touchedFiles[]      = $file;
      }
      foreach ($typeResult['errors'] as $error) {
        $result['errors'][] = $error;
      }
    }

    // Files on disk that were not touched = would be deleted as orphans.
    foreach ($this->scanContentExportFiles() as $relpath) {
      if (!in_array($relpath, $touchedFiles)) {
        $result['deleted'][] = $relpath;
      }
    }

    return $result;
  }

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

  /**
   * Export all content to content_export/.
   *
   * @return array{exported: string[], skipped: string[], deleted: string[], errors: string[]}
   *   Relative paths of files written, unchanged, removed, and any errors.
   */
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
  private function exportEntityType(string $entity_type, BaseExporter $exporter, bool $dryRun = FALSE): array {
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
      // Build the list of translations to export: default first, then the rest.
      $translations = [$entity];
      if ($entity instanceof TranslatableInterface) {
        foreach ($entity->getTranslationLanguages(FALSE) as $langcode => $language) {
          $translations[] = $entity->getTranslation($langcode);
        }
      }

      foreach ($translations as $translation) {
        try {
          $fileResult = $exporter->exportToFile($translation, $dryRun);
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
          $errors[] = $entity_type . ':' . $translation->id() . ':' . $translation->language()->getId() . ': ' . $e->getMessage();
          $this->logger->error(
            'Failed to export @type @id (@lang): @message',
            ['@type' => $entity_type, '@id' => (string) $translation->id(), '@lang' => $translation->language()->getId(), '@message' => $e->getMessage()]
          );
        }
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
