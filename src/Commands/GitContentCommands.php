<?php

namespace Drupal\git_content\Commands;

use Drupal\git_content\Exporter\MarkdownExporter;
use Drupal\git_content\Importer\MarkdownImporter;
use Drush\Commands\DrushCommands;

/**
 * Drush commands to export and import content to/from Git.
 *
 * Usage:
 *   drush git-content:export              Export all content types
 *   drush git-content:export nodes        Export nodes only
 *   drush git-content:export taxonomy     Export taxonomy terms only
 *   drush git-content:export media        Export media only
 *   drush git-content:export blocks       Export block content only
 *   drush git-content:export files        Export file entities only
 *   drush git-content:export users        Export users only
 *   drush git-content:export menus        Export menu links only
 *   drush git-content:import              Import all .md files
 */
class GitContentCommands extends DrushCommands {

  public function __construct(
    protected MarkdownExporter $exporter,
    protected MarkdownImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Export Drupal content to versionable Markdown files.
   *
   * @param string $type
   *   Content type to export: all (default), nodes, taxonomy, media, blocks,
   *   files, users, menus.
   *
   * @command git-content:export
   * @aliases gce
   * @usage drush git-content:export
   *   Export all content types and remove stale files.
   * @usage drush git-content:export nodes
   *   Export nodes only (no orphan cleanup).
   */
  public function export(string $type = 'all'): void {
    // Map display names to Drupal entity type machine names.
    $typeMap = [
      'nodes'    => 'node',
      'taxonomy' => 'taxonomy_term',
      'media'    => 'media',
      'blocks'   => 'block_content',
      'files'    => 'file',
      'users'    => 'user',
      'menus'    => 'menu_link_content',
    ];

    if ($type === 'all') {
      $this->logger()->notice('Exporting all content...');
      $result = $this->exporter->exportAll();
    }
    elseif (isset($typeMap[$type])) {
      $this->logger()->notice("Exporting {$type}...");
      $result = $this->exporter->exportTypes([$typeMap[$type]]);
    }
    else {
      $this->logger()->error("Unknown type '{$type}'. Valid types: all, " . implode(', ', array_keys($typeMap)));
      return;
    }

    foreach ($result['errors'] as $error) {
      $this->logger()->error($error);
    }

    $this->logger()->success(sprintf(
      'Export completed: %d exported, %d unchanged, %d deleted, %d errors.',
      count($result['exported']),
      count($result['skipped']),
      count($result['deleted']),
      count($result['errors']),
    ));
  }

  /**
   * Import Markdown files from content_export/ into Drupal.
   *
   * @command git-content:import
   * @aliases gci
   * @usage drush git-content:import
   *   Import all Markdown files in content_export/.
   */
  public function import(): void {
    $this->logger()->notice('Starting import from content_export/...');

    $result = $this->importer->importAll();

    foreach ($result['errors'] as $error) {
      $this->logger()->error($error);
    }

    $this->logger()->success(sprintf(
      'Import completed: %d created, %d updated, %d skipped, %d deleted, %d errors.',
      count($result['imported']),
      count($result['updated']),
      count($result['skipped']),
      count($result['deleted']),
      count($result['errors']),
    ));
  }

}
