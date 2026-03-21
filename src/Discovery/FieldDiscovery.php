<?php

namespace Drupal\git_content\Discovery;

use Drupal\git_content\Utility\ManagedFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Discover relevant fields for an entity bundle.
 */
class FieldDiscovery {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

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
    static $cache = [];

    $key = "$entity_type:$bundle";
    if (isset($cache[$key])) {
      return $cache[$key];
    }

    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    $relevant = [];
    foreach ($fields as $field_name => $field_definition) {
      if (in_array($field_name, ManagedFields::CORE)) {
        continue;
      }
      $relevant[$field_name] = $field_definition;
    }

    return $cache[$key] = $relevant;
  }

}