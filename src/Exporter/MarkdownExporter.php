<?php

namespace Drupal\git_content\Exporter;

use Drupal\git_content\Utility\ContentExportTrait;
use Drupal\git_content\Utility\SummaryTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

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

  const FORMAT_VERSION = 1;

  protected LoggerInterface $logger;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected LanguageManagerInterface $languageManager,
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
    $touchedSet = [];

    foreach ($this->exporterMap() as $entity_type => $exporter) {
      $typeResult = $this->exportEntityType($entity_type, $exporter, TRUE);

      foreach ($typeResult['exported_files'] as $file) {
        $result['exported'][] = $file;
        $touchedSet[$file]    = TRUE;
      }
      foreach ($typeResult['skipped_files'] as $file) {
        $result['skipped'][] = $file;
        $touchedSet[$file]   = TRUE;
      }
      foreach ($typeResult['errors'] as $error) {
        $result['errors'][] = $error;
      }
    }

    // Files on disk that were not touched = would be deleted as orphans.
    foreach ($this->scanContentExportFiles() as $relpath) {
      if (!isset($touchedSet[$relpath])) {
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
    $touchedSet = array_flip($touchedFiles);
    $allFiles   = $this->scanContentExportFiles();
    $orphans    = array_filter($allFiles, fn($f) => !isset($touchedSet[$f]));
    foreach ($orphans as $file) {
      $result['deleted'][] = $file;
      $path = $this->contentExportDir() . '/' . $file;
      if (is_file($path)) {
        @unlink($path);
      }
    }

    $this->writeSiteYaml();

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
   * Re-export a single entity (all translations) identified by UUID.
   *
   * Used by the importer after a real import to normalise the .md file to the
   * canonical exporter format, ensuring the next export preview shows the file
   * as unchanged.
   */
  public function exportEntityByUuid(string $uuid, string $entityType): void {
    $exporter = $this->exporterMap()[$entityType] ?? NULL;
    if (!$exporter) {
      return;
    }

    $entities = $this->entityTypeManager->getStorage($entityType)
      ->loadByProperties(['uuid' => $uuid]);
    if (empty($entities)) {
      return;
    }

    $entity = reset($entities);

    foreach ($this->getEntityTranslations($entity) as $translation) {
      try {
        $exporter->exportToFile($translation);
      }
      catch (\Exception $e) {
        // Ignore individual translation failures during normalisation.
      }
    }
  }

  /**
   * Write content_export/site.yaml with site-wide metadata for SSG tooling.
   *
   * Contains the format version (so SSG tools can detect breaking changes),
   * the list of enabled content languages, and the default language.
   * This file is idempotent: running export multiple times produces the same
   * output and only rewrites the file when something has changed.
   */
  private function writeSiteYaml(): void {
    $languages = array_keys($this->languageManager->getLanguages());
    $default   = $this->languageManager->getDefaultLanguage()->getId();

    $data = [
      'git_content_format' => self::FORMAT_VERSION,
      'languages'          => $languages,
      'default_language'   => $default,
    ];

    $yaml    = Yaml::dump($data, 2, 2);
    $path    = $this->contentExportDir() . '/site.yaml';
    $current = file_exists($path) ? file_get_contents($path) : '';

    if ($current !== $yaml) {
      file_put_contents($path, $yaml);
    }
  }

  /**
   * Returns the entity-type → exporter map used by all export methods.
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
      foreach ($this->getEntityTranslations($entity) as $translation) {
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

  /**
   * Returns all translations of an entity, default translation first.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  private function getEntityTranslations(\Drupal\Core\Entity\EntityInterface $entity): array {
    $translations = [$entity];
    if ($entity instanceof TranslatableInterface) {
      foreach ($entity->getTranslationLanguages(FALSE) as $langcode => $language) {
        $translations[] = $entity->getTranslation($langcode);
      }
    }
    return $translations;
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
