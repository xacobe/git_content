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
  private array $handlers;

  /** @var array<string, ?\Drupal\git_content\Handler\FieldHandlerInterface> */
  private array $cache = [];

  /**
   * @param iterable<\Drupal\git_content\Handler\FieldHandlerInterface> $handlers
   *   All services tagged with 'git_content.field_handler', collected via
   *   !tagged_iterator in services.yml.
   */
  public function __construct(iterable $handlers) {
    $this->handlers = $handlers instanceof \Traversable
      ? iterator_to_array($handlers)
      : $handlers;
  }

  /**
   * Find the first handler that supports the given field type and definition.
   *
   * Returns NULL when no registered handler matches, so the caller falls
   * through to its built-in normalization logic.
   *
   * Results are cached by field type + field name for the lifetime of the
   * request, since field definitions are immutable at runtime.
   */
  public function find(string $field_type, FieldDefinitionInterface $definition): ?FieldHandlerInterface {
    $key = $field_type . ':' . $definition->getName();
    if (array_key_exists($key, $this->cache)) {
      return $this->cache[$key];
    }
    foreach ($this->handlers as $handler) {
      if ($handler->supports($field_type, $definition)) {
        return $this->cache[$key] = $handler;
      }
    }
    return $this->cache[$key] = NULL;
  }

}
