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
 * Comandos Drush para exportar e importar contenido desde/hacia Git.
 *
 * Uso:
 *   drush git-content:export          # Exporta todo
 *   drush git-content:export nodes    # Solo nodos
 *   drush git-content:export taxonomy # Solo términos
 *   drush git-content:export media    # Solo media
 *   drush git-content:import          # Importa todo
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
   * Exporta contenido de Drupal a archivos Markdown versionables.
   *
   * @param string $type
   *   Tipo a exportar: all, nodes, taxonomy, media. Por defecto: all.
   *
   * @command git-content:export
   * @aliases gce
   * @usage drush git-content:export
   *   Exporta nodos, taxonomía y media.
   * @usage drush git-content:export nodes
   *   Exporta solo los nodos.
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

    $this->logger()->success('Exportación completada.');
  }

  /**
   * Importa archivos Markdown de content_export/ a Drupal.
   *
   * @command git-content:import
   * @aliases gci
   * @usage drush git-content:import
   *   Importa todos los archivos Markdown de content_export/.
   */
  public function import(): void {
    $this->logger()->notice('Iniciando importación desde content_export/...');

    $result = $this->importer->importAll();

    foreach ($result['imported'] as $file) {
      $this->logger()->success("Creado: $file");
    }
    foreach ($result['updated'] as $file) {
      $this->logger()->notice("Actualizado: $file");
    }
    foreach ($result['errors'] as $error) {
      $this->logger()->error("Error: $error");
    }

    $total = count($result['imported']) + count($result['updated']);
    $this->logger()->success(
      "Importación completada: {$total} archivos procesados, " . count($result['errors']) . " errores."
    );
  }

  // ---------------------------------------------------------------------------
  // Exportadores privados
  // ---------------------------------------------------------------------------

  private function exportNodes(): void {
    $this->logger()->notice('Exportando nodos...');
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $nodes = $storage->loadMultiple($nids);
    $count = 0;

    foreach ($nodes as $node) {
      try {
        $filepath = $this->nodeExporter->exportToFile($node);
        $this->logger()->info("  ✔ Nodo {$node->id()} ({$node->bundle()}): $filepath");
        $count++;
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ Nodo {$node->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count nodos exportados.");
  }

  private function exportTaxonomy(): void {
    $this->logger()->notice('Exportando términos de taxonomía...');
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $terms = $storage->loadMultiple($tids);
    $count = 0;

    foreach ($terms as $term) {
      try {
        $filepath = $this->taxonomyExporter->exportToFile($term);
        $this->logger()->info("  ✔ Término {$term->id()} ({$term->bundle()}): $filepath");
        $count++;
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ Término {$term->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count términos exportados.");
  }

  private function exportFiles(): void {
    $this->logger()->notice('Exportando archivos...');
    $files = $this->fileExporter->exportAll();
    $this->logger()->notice('  ' . count($files) . ' archivos exportados.');
  }

  private function exportUsers(): void {
    $this->logger()->notice('Exportando usuarios...');
    $files = $this->userExporter->exportAll();
    foreach ($files as $f) {
      $this->logger()->info("  ✔ $f");
    }
    $this->logger()->notice('  ' . count($files) . ' usuarios exportados.');
  }

  private function exportMenuLinks(): void {
    $this->logger()->notice('Exportando enlaces de menú...');
    $files = $this->menuLinkExporter->exportAll();
    foreach ($files as $filepath) {
      $this->logger()->info("  ✔ $filepath");
    }
    $this->logger()->notice('  ' . count($files) . ' enlaces exportados.');
  }

  private function exportBlocks(): void {
    $this->logger()->notice('Exportando bloques de contenido...');
    $storage = $this->entityTypeManager->getStorage('block_content');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $count = 0;

    foreach ($storage->loadMultiple($ids) as $block) {
      try {
        $filepath = $this->blockContentExporter->exportToFile($block);
        $this->logger()->info("  ✔ Bloque {$block->id()} ({$block->bundle()}): $filepath");
        $count++;
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ Bloque {$block->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count bloques exportados.");
  }

  private function exportMedia(): void {
    $this->logger()->notice('Exportando media...');
    $storage = $this->entityTypeManager->getStorage('media');
    $mids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $medias = $storage->loadMultiple($mids);
    $count = 0;

    foreach ($medias as $media) {
      try {
        $filepath = $this->mediaExporter->exportToFile($media);
        $this->logger()->info("  ✔ Media {$media->id()} ({$media->bundle()}): $filepath");
        $count++;
      }
      catch (\Exception $e) {
        $this->logger()->error("  ✘ Media {$media->id()}: " . $e->getMessage());
      }
    }

    $this->logger()->notice("  $count media exportados.");
  }

}