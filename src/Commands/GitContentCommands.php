<?php

namespace Drupal\git_content\Commands;

use Drupal\git_content\Exporter\NodeExporter;
use Drupal\git_content\Exporter\TaxonomyExporter;
use Drupal\git_content\Exporter\MediaExporter;
use Drupal\git_content\Exporter\BlockContentExporter;
use Drupal\git_content\Exporter\FileExporter;
use Drupal\git_content\Exporter\UserExporter;
use Drupal\git_content\Exporter\MenuLinkExporter;
use Drupal\git_content\Importer\MarkdownImporter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands to export and import content to/from Git.
 *
 * Usage:
 *   drush git-content:export          # Export everything
 *   drush git-content:export nodes    # Export nodes only
 *   drush git-content:export taxonomy # Export taxonomy terms only
 *   drush git-content:export media    # Export media only
 *   drush git-content:import          # Import everything
 */
class GitContentCommands extends DrushCommands {

  protected NodeExporter $nodeExporter;
  protected TaxonomyExporter $taxonomyExporter;
  protected MediaExporter $mediaExporter;
  protected BlockContentExporter $blockContentExporter;
  protected FileExporter $fileExporter;
  protected UserExporter $userExporter;
  protected MenuLinkExporter $menuLinkExporter;
  protected MarkdownImporter $importer;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    NodeExporter $nodeExporter,
    TaxonomyExporter $taxonomyExporter,
    MediaExporter $mediaExporter,
    BlockContentExporter $blockContentExporter,
    FileExporter $fileExporter,
    UserExporter $userExporter,
    MenuLinkExporter $menuLinkExporter,
    MarkdownImporter $importer,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
    $this->nodeExporter     = $nodeExporter;
    $this->taxonomyExporter = $taxonomyExporter;
    $this->mediaExporter        = $mediaExporter;
    $this->blockContentExporter = $blockContentExporter;
    $this->fileExporter         = $fileExporter;
    $this->userExporter         = $userExporter;
    $this->menuLinkExporter     = $menuLinkExporter;
    $this->importer         = $importer;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Export Drupal content to versionable Markdown files.
   *
   * @param string $type
   *   Type to export: all, nodes, taxonomy, media. Default: all.
   *
   * @command git-content:export
   * @aliases gce
   * @usage drush git-content:export
   *   Export nodes, taxonomy, and media.
   * @usage drush git-content:export nodes
   *   Export nodes only.
   */
  public function export(string $type = 'all'): void {
    $types = match ($type) {
      'nodes'    => ['nodes'],
      'taxonomy' => ['taxonomy'],
      'media'    => ['media'],
      default    => ['files', 'users', 'nodes', 'taxonomy', 'media', 'blocks', 'menus'],
    };

    foreach ($types as $t) {
      match ($t) {
        'nodes'    => $this->exportNodes(),
        'taxonomy' => $this->exportTaxonomy(),
        'media'    => $this->exportMedia(),
      'blocks'   => $this->exportBlocks(),
      'files'    => $this->exportFiles(),
      'users'    => $this->exportUsers(),
      'menus'    => $this->exportMenuLinks(),
      };
    }

    $this->logger()->success('Export completed.');
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

    foreach ($result['imported'] as $file) {
      $this->logger()->success("Created: $file");
    }
    foreach ($result['updated'] as $file) {
      $this->logger()->notice("Updated: $file");
    }
    foreach ($result['errors'] as $error) {
      $this->logger()->error("Error: $error");
    }

    $total = count($result['imported']) + count($result['updated']);
    $this->logger()->success(
      "Import completed: {$total} files processed, " . count($result['errors']) . " errors."
    );
  }

  // ---------------------------------------------------------------------------
  // Private exporters
  // ---------------------------------------------------------------------------

  private function exportNodes(): void {
    $this->logger()->notice('Exporting nodes...');
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $nodes = $storage->loadMultiple($nids);
    $count = 0;

    $skipped = 0;

    foreach ($nodes as $node) {
      try {
        $result = $this->nodeExporter->exportToFile($node);
        $filepath = is_array($result) ? $result['path'] : $result;
        $skippedFile = is_array($result) ? ($result['skipped'] ?? FALSE) : FALSE;

        if ($skippedFile) {
          $skipped++;
          $this->logger()->info("  → Node {$node->id()} ({$node->bundle()}): $filepath");
        }
        else {
          $this->logger()->info("  ✔ Node {$node->id()} ({$node->bundle()}): $filepath");
          $count++;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ Node {$node->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count nodes exported, $skipped unchanged.");
  }

  private function exportTaxonomy(): void {
    $this->logger()->notice('Exporting taxonomy terms...');
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $terms = $storage->loadMultiple($tids);
    $count = 0;

    $skipped = 0;

    foreach ($terms as $term) {
      try {
        $result = $this->taxonomyExporter->exportToFile($term);
        $filepath = is_array($result) ? $result['path'] : $result;
        $skippedFile = is_array($result) ? ($result['skipped'] ?? FALSE) : FALSE;

        if ($skippedFile) {
          $skipped++;
          $this->logger()->info("  → Term {$term->id()} ({$term->bundle()}): $filepath");
        }
        else {
          $this->logger()->info("  ✔ Term {$term->id()} ({$term->bundle()}): $filepath");
          $count++;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ Term {$term->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count terms exported, $skipped unchanged.");
  }

  private function exportFiles(): void {
    $this->logger()->notice('Exporting files...');
    $files = $this->fileExporter->exportAll();
    $this->logger()->notice('  ' . count($files) . ' files exported.');
  }

  private function exportUsers(): void {
    $this->logger()->notice('Exporting users...');
    $storage = $this->entityTypeManager->getStorage('user');
    $uids = $storage->getQuery()->accessCheck(FALSE)->condition('uid', 0, '>')->execute();
    $users = $storage->loadMultiple($uids);

    $count = 0;
    $skipped = 0;

    foreach ($users as $user) {
      try {
        $result = $this->userExporter->exportToFile($user);
        $filepath = is_array($result) ? $result['path'] : $result;
        $skippedFile = is_array($result) ? ($result['skipped'] ?? FALSE) : FALSE;

        if ($skippedFile) {
          $skipped++;
          $this->logger()->info("  → User {$user->id()}: $filepath");
        }
        else {
          $this->logger()->info("  ✔ User {$user->id()}: $filepath");
          $count++;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ User {$user->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count users exported, $skipped unchanged.");
  }

  private function exportMenuLinks(): void {
    $this->logger()->notice('Exporting menu links...');
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $links = $storage->loadMultiple($ids);

    $count = 0;
    $skipped = 0;

    foreach ($links as $link) {
      try {
        $result = $this->menuLinkExporter->exportToFile($link);
        $filepath = is_array($result) ? $result['path'] : $result;
        $skippedFile = is_array($result) ? ($result['skipped'] ?? FALSE) : FALSE;

        if ($skippedFile) {
          $skipped++;
          $this->logger()->info("  → Menu link {$link->id()}: $filepath");
        }
        else {
          $this->logger()->info("  ✔ Menu link {$link->id()}: $filepath");
          $count++;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ Menu link {$link->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count links exported, $skipped unchanged.");
  }

  private function exportBlocks(): void {
    $this->logger()->notice('Exporting block content...');
    $storage = $this->entityTypeManager->getStorage('block_content');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $count = 0;

    $skipped = 0;

    foreach ($storage->loadMultiple($ids) as $block) {
      try {
        $result = $this->blockContentExporter->exportToFile($block);
        $filepath = is_array($result) ? $result['path'] : $result;
        $skippedFile = is_array($result) ? ($result['skipped'] ?? FALSE) : FALSE;

        if ($skippedFile) {
          $skipped++;
          $this->logger()->info("  → Block {$block->id()} ({$block->bundle()}): $filepath");
        }
        else {
          $this->logger()->info("  ✔ Block {$block->id()} ({$block->bundle()}): $filepath");
          $count++;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ Block {$block->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count blocks exported, $skipped unchanged.");
  }

  private function exportMedia(): void {
    $this->logger()->notice('Exporting media...');
    $storage = $this->entityTypeManager->getStorage('media');
    $mids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $medias = $storage->loadMultiple($mids);
    $count = 0;

    $skipped = 0;

    foreach ($medias as $media) {
      try {
        $result = $this->mediaExporter->exportToFile($media);
        $filepath = is_array($result) ? $result['path'] : $result;
        $skippedFile = is_array($result) ? ($result['skipped'] ?? FALSE) : FALSE;

        if ($skippedFile) {
          $skipped++;
          $this->logger()->info("  → Media {$media->id()} ({$media->bundle()}): $filepath");
        }
        else {
          $this->logger()->info("  ✔ Media {$media->id()} ({$media->bundle()}): $filepath");
          $count++;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ Media {$media->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count media exported, $skipped unchanged.");
  }

}