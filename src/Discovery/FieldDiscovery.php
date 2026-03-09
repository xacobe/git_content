<?php

namespace Drupal\git_content\Discovery;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Discover relevant fields for an entity bundle.
 */
class FieldDiscovery {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity_type.manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity_field.manager service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * Get the list of relevant fields for a bundle.
   *
   * @param string $entity_type
   *   Entity type, e.g. 'node'.
   * @param string $bundle
   *   Bundle, e.g. 'article'.
   *
   * @return FieldDefinitionInterface[]
   *   Array of relevant field definitions.
   */
  public function getFields(string $entity_type, string $bundle): array {
    // Use entity_field.manager to fetch field definitions.
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    $relevant = [];
    foreach ($fields as $field_name => $field_definition) {
      // Skip irrelevant fields
      if (in_array($field_name, ['nid', 'vid', 'uuid', 'langcode', 'revision_timestamp', 'revision_uid'])) {
        continue;
      }
      $relevant[$field_name] = $field_definition;
    }

    return $relevant;
  }

}