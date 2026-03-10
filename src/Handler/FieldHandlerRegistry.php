<?php

namespace Drupal\git_content\Handler;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Collects all git_content.field_handler tagged services.
 *
 * BaseExporter and BaseImporter use this registry to delegate normalization
 * and denormalization for field types they do not handle natively.
 */
class FieldHandlerRegistry {

  /** @var \Drupal\git_content\Handler\FieldHandlerInterface[] */
  private array $handlers = [];

  /**
   * @param iterable<\Drupal\git_content\Handler\FieldHandlerInterface> $handlers
   *   All services tagged with 'git_content.field_handler', collected via
   *   !tagged_iterator in services.yml.
   */
  public function __construct(iterable $handlers) {
    foreach ($handlers as $handler) {
      $this->handlers[] = $handler;
    }
  }

  /**
   * Find the first handler that supports the given field type and definition.
   *
   * Returns NULL when no registered handler matches, so the caller falls
   * through to its built-in normalization logic.
   */
  public function find(string $field_type, FieldDefinitionInterface $definition): ?FieldHandlerInterface {
    foreach ($this->handlers as $handler) {
      if ($handler->supports($field_type, $definition)) {
        return $handler;
      }
    }
    return NULL;
  }

}
