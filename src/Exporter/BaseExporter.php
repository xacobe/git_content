<?php

namespace Drupal\git_content\Exporter;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Clase base para todos los exportadores de entidades.
 *
 * Contiene la lógica común de normalización de campos y construcción del
 * frontmatter. Cada exportador concreto (NodeExporter, TaxonomyExporter…)
 * extiende esta clase e implementa los métodos específicos de su entidad.
 */
abstract class BaseExporter {

  protected FieldDiscovery $fieldDiscovery;
  protected MarkdownSerializer $serializer;

  /**
   * Campos base gestionados manualmente; se excluyen del bucle dinámico.
   */
  protected array $managedFields = [
    'nid', 'vid', 'uuid', 'langcode', 'status', 'created', 'changed',
    'uid', 'title', 'body', 'path', 'type',
    'revision_timestamp', 'revision_uid', 'revision_log', 'revision_log_message', 'revision_default',
    'revision_translation_affected', 'default_langcode',
    'content_translation_source', 'content_translation_outdated',
    // Usuarios (no exportar campos sensibles o irrelevantes)
    'pass', 'access', 'login', 'init',
    // Comentarios - no los exportamos para contenido estático
    'comment', 'comment_count', 'comment_status', 'last_comment_timestamp',
    'last_comment_name', 'last_comment_uid',
    // block_content system fields
    'id', 'revision_id', 'revision_created', 'revision_user', 'info', 'reusable',
  ];

  public function __construct(FieldDiscovery $fieldDiscovery, MarkdownSerializer $serializer) {
    $this->fieldDiscovery = $fieldDiscovery;
    $this->serializer = $serializer;
  }

  /**
   * Exporta la entidad a un archivo Markdown en disco.
   *
   * @return array{path: string, skipped: bool} Información del archivo generado.
   */
  abstract public function exportToFile(EntityInterface $entity): array;

  /**
   * Genera el contenido Markdown completo de la entidad.
   *
   * @return string Contenido del archivo .md.
   */
  abstract public function export(EntityInterface $entity): string;

  // ---------------------------------------------------------------------------
  // Helpers compartidos
  // ---------------------------------------------------------------------------

  /**
   * Construye el bloque de campos dinámicos agrupados por tipo
   * (taxonomy, media, references, extra).
   */
  protected function buildDynamicGroups(EntityInterface $entity, string $entity_type): array {
    $bundle = $entity->bundle();
    $fields = $this->fieldDiscovery->getFields($entity_type, $bundle);

    $taxonomy   = [];
    $media      = [];
    $references = [];
    $extra      = [];

    foreach ($fields as $field_name => $definition) {
      if (in_array($field_name, $this->managedFields)) {
        continue;
      }
      if (!$entity->hasField($field_name)) {
        continue;
      }
      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      $field_type = $definition->getType();
      $normalized = $this->normalizeField($field, $definition);

      if ($field_type === 'entity_reference') {
        $target_type = $definition->getSetting('target_type');
        if ($target_type === 'taxonomy_term') {
          $vocab = $definition->getSetting('handler_settings')['target_bundles'] ?? [];
          $key = !empty($vocab) ? implode('_', array_keys($vocab)) : $field_name;
          $taxonomy[$key] = $normalized;
        }
        elseif (in_array($target_type, ['node', 'media'])) {
          $references[$field_name] = $normalized;
        }
        else {
          $extra[$field_name] = $normalized;
        }
      }
      elseif (in_array($field_type, ['image', 'file'])) {
        $media[$field_name] = $normalized;
      }
      else {
        $extra[$field_name] = $normalized;
      }
    }

    return compact('taxonomy', 'media', 'references', 'extra');
  }

  /**
   * Normaliza el valor de un campo al formato limpio para el frontmatter.
   */
  protected function normalizeField($field, FieldDefinitionInterface $definition): mixed {
    $field_type  = $definition->getType();
    $items       = $field->getValue();
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
    $is_multiple = $cardinality !== 1;

    $normalized = [];

    foreach ($items as $item) {
      switch ($field_type) {
        case 'string':
        case 'string_long':
        case 'list_string':
          $normalized[] = $item['value'] ?? NULL;
          break;

        case 'boolean':
          $normalized[] = (bool) ($item['value'] ?? FALSE);
          break;

        case 'integer':
        case 'list_integer':
        case 'list_float':
        case 'decimal':
        case 'float':
          $normalized[] = $item['value'] ?? NULL;
          break;

        case 'datetime':
          $normalized[] = isset($item['value']) ? substr($item['value'], 0, 10) : NULL;
          break;

        case 'timestamp':
          $normalized[] = isset($item['value']) ? date('Y-m-d', $item['value']) : NULL;
          break;

        case 'link':
          $normalized[] = [
            'url'   => $item['uri'] ?? NULL,
            'title' => $item['title'] ?? NULL,
          ];
          break;

        case 'image':
        case 'file':
          $file = \Drupal::service('entity_type.manager')
            ->getStorage('file')
            ->load($item['target_id'] ?? 0);
          $normalized[] = $file ? basename($file->getFileUri()) : NULL;
          break;

        case 'entity_reference':
          $target_type = $definition->getSetting('target_type');
          $target_id   = $item['target_id'] ?? NULL;
          if ($target_id && $target_type === 'taxonomy_term') {
            $term = \Drupal::service('entity_type.manager')
              ->getStorage('taxonomy_term')->load($target_id);
            $normalized[] = $term ? $term->label() : $target_id;
          }
          elseif ($target_id && $target_type === 'node') {
            $node = \Drupal::service('entity_type.manager')
              ->getStorage('node')->load($target_id);
            $normalized[] = $node ? $this->getSlug($node) : $target_id;
          }
          else {
            $normalized[] = $target_id;
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $normalized[] = $item['value'] ?? NULL;
          break;

        default:
          $normalized[] = $item['value'] ?? (count($item) === 1 ? reset($item) : $item);
      }
    }

    if (!$is_multiple && count($normalized) === 1) {
      return $normalized[0];
    }

    return $normalized ?: NULL;
  }

  /**
   * Obtiene el slug a partir del alias de path de la entidad.
   */
  protected function getSlug(EntityInterface $entity): string {
    if ($entity->hasField('path') && !$entity->get('path')->isEmpty()) {
      $alias = $entity->get('path')->alias;
      if ($alias) {
        return ltrim(basename($alias), '/');
      }
    }
    return $entity->getEntityTypeId() . '-' . $entity->id();
  }

  /**
   * Obtiene el alias de path completo de la entidad.
   */
  protected function getPathAlias(EntityInterface $entity): string {
    if ($entity->hasField('path') && !$entity->get('path')->isEmpty()) {
      $alias = $entity->get('path')->alias;
      if ($alias) {
        return $alias;
      }
    }
    return '/' . $entity->getEntityTypeId() . '/' . $entity->id();
  }

  /**
   * Añade un checksum SHA1 al frontmatter para detectar cambios en el archivo.
   *
   * El checksum se calcula sobre una representación canónica del frontmatter
   * + body (JSON con claves ordenadas) para que sea estable aun cuando cambie
   * el orden del YAML o el formato de serialización.
   */
  protected function addChecksum(array $frontmatter, string $body): array {
    // Aseguramos que el checksum se calcula sobre la misma estructura que el
    // importador utiliza para comparar (grupos aplanados).
    $fm = $this->serializer->flattenGroups($frontmatter);
    unset($fm['checksum']);
    $fm = array_filter($fm, fn($key) => !preg_match('/^_+$/', (string) $key), ARRAY_FILTER_USE_KEY);

    $canonical = $this->canonicalizeForHash(['frontmatter' => $fm, 'body' => $body]);
    $hash = sha1(json_encode($canonical, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION));

    $frontmatter['checksum'] = $hash;

    return $frontmatter;
  }

  /**
   * Acorta un UUID a 8 caracteres para legibilidad en el frontmatter.
   */
  protected function shortenUuid(string $uuid): string {
    return substr(str_replace('-', '', $uuid), 0, 8);
  }

  /**
   * Canonicaliza una estructura de datos para hashing.
   *
   * Asegura un orden de claves estable y aplica la misma transformación a
   * arrays anidados (ordenando recursivamente) para que el hash sea
   * determinista sin depender de constantes JSON específicas.
   */
  protected function canonicalizeForHash(mixed $data): mixed {
    if (is_array($data)) {
      $keys = array_keys($data);
      $is_sequential = $keys === range(0, count($data) - 1);

      if ($is_sequential) {
        $data = array_map(fn($item) => $this->canonicalizeForHash($item), $data);

        // Para valores escalares, ordenar para que cambios de orden no modifiquen el checksum.
        $all_scalars = array_reduce($data, fn($carry, $item) => $carry && (is_null($item) || is_scalar($item)), TRUE);
        if ($all_scalars) {
          sort($data);
        }
        else {
          // Para arrays de objetos/arrays, ordenar por su representación JSON.
          usort($data, fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
        }

        return $data;
      }

      // Asociativo: ordenar claves y canonicalizar recursivamente.
      ksort($data);
      foreach ($data as $key => $value) {
        $data[$key] = $this->canonicalizeForHash($value);
      }
    }
    return $data;
  }

  /**
   * Devuelve el UUID corto de la entidad original si esta es una traducción.
   */
  protected function getTranslationOf(EntityInterface $entity): ?string {
    if (!$entity->isDefaultTranslation()) {
      return $this->shortenUuid($entity->getUntranslated()->uuid());
    }
    return NULL;
  }

  /**
   * Escribe un archivo solo si su contenido ha cambiado respecto al existente.
   *
   * @return bool
   *   TRUE si se escribió el archivo (nuevo o actualizado), FALSE si se omitió.
   */
  protected function writeIfChanged(string $filepath, string $content): bool {
    if (file_exists($filepath)) {
      $existing = file_get_contents($filepath);
      if ($existing === $content) {
        return false;
      }
    }

    file_put_contents($filepath, $content);
    return true;
  }

  /**
   * Crea el directorio de exportación si no existe.
   */
  protected function ensureDir(string $dir): void {
    if (!file_exists($dir)) {
      mkdir($dir, 0775, TRUE);
    }
  }

}