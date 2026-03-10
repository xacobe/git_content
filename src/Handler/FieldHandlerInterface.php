<?php

namespace Drupal\git_content\Handler;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Interface for pluggable field normalize/denormalize handlers.
 *
 * Implement this interface in a submodule (e.g. git_content_layout,
 * git_content_paragraphs) and tag the service with 'git_content.field_handler'
 * to extend export/import support for any custom or contrib field type without
 * touching the parent module.
 *
 * Example service registration in a submodule:
 * @code
 * services:
 *   git_content_layout.layout_handler:
 *     class: Drupal\git_content_layout\Handler\LayoutFieldHandler
 *     arguments: ['@entity_type.manager']
 *     tags:
 *       - { name: git_content.field_handler }
 * @endcode
 */
interface FieldHandlerInterface {

  /**
   * Whether this handler is responsible for the given field.
   *
   * Called before both normalize() and denormalize(). Return TRUE only for
   * field types this handler knows how to process.
   *
   * @param string $field_type
   *   The field type plugin ID (e.g. 'layout_builder__layout', 'paragraphs').
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The full field definition (target type, settings, cardinality, etc.).
   */
  public function supports(string $field_type, FieldDefinitionInterface $definition): bool;

  /**
   * Normalize a field to a serializable scalar or array for frontmatter.
   *
   * Called during export. The return value must be serializable to YAML.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The populated field item list from the entity being exported.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return mixed
   *   A scalar, array, or NULL suitable for YAML serialization.
   */
  public function normalize(FieldItemListInterface $field, FieldDefinitionInterface $definition): mixed;

  /**
   * Denormalize a frontmatter value back to a Drupal field value.
   *
   * Called during import. Must return a value compatible with
   * EntityInterface::set() for the field.
   *
   * @param mixed $value
   *   The raw value as parsed from the frontmatter YAML.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return mixed
   *   A value suitable for EntityInterface::set($field_name, $value).
   */
  public function denormalize(mixed $value, FieldDefinitionInterface $definition): mixed;

}
