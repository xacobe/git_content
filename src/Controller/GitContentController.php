<?php

namespace Drupal\git_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Exporter\EntityExporter;
use Drupal\git_content\Importer\EntityImporter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class GitContentController extends ControllerBase {

  protected EntityExporter $exporter;
  protected EntityImporter $importer;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $fieldDiscovery = new FieldDiscovery($entityTypeManager, $entityFieldManager);
    $this->exporter = new EntityExporter($fieldDiscovery);
    $this->importer = new EntityImporter($fieldDiscovery, $entityTypeManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Exporta todos los nodos a archivos Markdown en content_export/.
   */
  public function exportGit(): array {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->execute();

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    $output = '<strong>Git Content Export</strong><br><br>';

    foreach ($nodes as $node) {
      try {
        $filepath = $this->exporter->exportToFile($node);
        $output .= '✔ Nodo ' . $node->id() . ' (' . $node->bundle() . '): ' . $filepath . '<br>';
      }
      catch (\Exception $e) {
        $output .= '✘ Nodo ' . $node->id() . ': error — ' . $e->getMessage() . '<br>';
      }
    }

    return ['#markup' => $output];
  }

  /**
   * Importa todos los archivos Markdown de content_export/ a nodos de Drupal.
   */
  public function importGit(): array {
    $result = $this->importer->importAll();

    $output = '<strong>Git Content Import</strong><br><br>';

    if (!empty($result['imported'])) {
      $output .= '<strong>Creados (' . count($result['imported']) . '):</strong><br>';
      foreach ($result['imported'] as $file) {
        $output .= '✔ ' . $file . '<br>';
      }
      $output .= '<br>';
    }

    if (!empty($result['updated'])) {
      $output .= '<strong>Actualizados (' . count($result['updated']) . '):</strong><br>';
      foreach ($result['updated'] as $file) {
        $output .= '↻ ' . $file . '<br>';
      }
      $output .= '<br>';
    }

    if (!empty($result['errors'])) {
      $output .= '<strong>Errores (' . count($result['errors']) . '):</strong><br>';
      foreach ($result['errors'] as $error) {
        $output .= '✘ ' . $error . '<br>';
      }
    }

    if (empty($result['imported']) && empty($result['updated']) && empty($result['errors'])) {
      $output .= 'No se encontraron archivos para importar en content_export/.';
    }

    return ['#markup' => $output];
  }

}