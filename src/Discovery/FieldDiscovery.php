<?php

namespace Drupal\git_content\Discovery;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Descubre los campos relevantes de un bundle de entidad.
 */
class FieldDiscovery {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Servicio entity_type.manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Servicio entity_field.manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * Obtiene la lista de campos relevantes de un bundle.
   *
   * @param string $entity_type
   *   Tipo de entidad, por ejemplo 'node'.
   * @param string $bundle
   *   Bundle, por ejemplo 'article'.
   *
   * @return FieldDefinitionInterface[]
   *   Array de definiciones de campo relevantes.
   */
  public function getFields(string $entity_type, string $bundle): array {
    // Usar entity_field.manager para obtener los field definitions
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    $relevant = [];
    foreach ($fields as $field_name => $field_definition) {
      // Ignorar campos irrelevantes
      if (in_array($field_name, ['nid', 'vid', 'uuid', 'langcode', 'revision_timestamp', 'revision_uid'])) {
        continue;
      }
      $relevant[$field_name] = $field_definition;
    }

    return $relevant;
  }

}