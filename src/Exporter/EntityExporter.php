<?php

namespace Drupal\git_content\Exporter;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Exporta entidades (nodos) a Markdown con frontmatter YAML limpio y legible.
 */
class EntityExporter {

  protected FieldDiscovery $fieldDiscovery;

  /**
   * Campos base que se gestionan manualmente en el frontmatter.
   * Se excluyen del bucle de campos dinámicos para evitar duplicados.
   */
  protected array $managedFields = [
    'nid', 'vid', 'uuid', 'langcode', 'status', 'created', 'changed',
    'uid', 'title', 'body', 'path', 'type',
    // Campos de auditoría/sistema que no aportan valor al frontmatter
    'revision_timestamp', 'revision_uid', 'revision_log', 'revision_default',
    'revision_translation_affected', 'default_langcode',
    'content_translation_source', 'content_translation_outdated',
  ];

  /**
   * Tipos de campo que se tratan como taxonomía.
   */
  protected array $taxonomyFieldTypes = [
    'entity_reference',
  ];

  /**
   * Constructor.
   *
   * @param \Drupal\git_content\Discovery\FieldDiscovery $fieldDiscovery
   *   Servicio para descubrir campos.
   */
  public function __construct(FieldDiscovery $fieldDiscovery) {
    $this->fieldDiscovery = $fieldDiscovery;
  }

  /**
   * Exporta un nodo a Markdown y lo guarda en carpeta versionable.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Nodo a exportar.
   *
   * @return string
   *   Ruta del archivo Markdown generado.
   */
  public function exportToFile(EntityInterface $entity): string {
    $markdown = $this->export($entity);

    $export_dir = DRUPAL_ROOT . '/content_export/' . $entity->bundle();
    if (!file_exists($export_dir)) {
      mkdir($export_dir, 0775, TRUE);
    }

    // Usar el alias de path como nombre de archivo si existe, si no node-{nid}
    $slug = $this->getSlug($entity);
    $langcode = $entity->language()->getId();
    $filename = $slug . '-' . $langcode . '.md';
    $filepath = $export_dir . '/' . $filename;

    file_put_contents($filepath, $markdown);

    return $filepath;
  }

  /**
   * Genera el contenido Markdown de un nodo con frontmatter limpio.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Nodo a exportar.
   *
   * @return string
   *   Markdown con frontmatter YAML.
   */
  public function export(EntityInterface $entity): string {
    $bundle = $entity->bundle();
    $langcode = $entity->language()->getId();
    $fields = $this->fieldDiscovery->getFields($entity->getEntityTypeId(), $bundle);

    // --- Campos base ---
    $frontmatter = [];
    $frontmatter['uuid'] = $this->shortenUuid($entity->uuid());
    $frontmatter['type'] = $bundle;
    $frontmatter['lang'] = $langcode;
    $frontmatter['status'] = $entity->isPublished() ? 'published' : 'draft';
    $frontmatter[''] = NULL; // línea en blanco visual

    // Título y slug
    $frontmatter['title'] = $entity->label();
    $frontmatter['slug'] = $this->getSlug($entity);
    $frontmatter['_'] = NULL; // línea en blanco visual

    // Fechas en formato legible
    $frontmatter['created'] = date('Y-m-d', $entity->getCreatedTime());
    $frontmatter['changed'] = date('Y-m-d', $entity->getChangedTime());
    $frontmatter['__'] = NULL; // línea en blanco visual

    // Path alias
    $path = $this->getPathAlias($entity);
    $frontmatter['path'] = $path;
    $frontmatter['___'] = NULL; // línea en blanco visual

    // --- Campos dinámicos agrupados ---
    $taxonomy = [];
    $media = [];
    $references = [];
    $extra = [];

    foreach ($fields as $field_name => $definition) {
      // Saltar campos ya gestionados manualmente
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

      // Clasificar por tipo
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

    // Agregar grupos al frontmatter solo si tienen contenido
    if (!empty($taxonomy)) {
      $frontmatter['taxonomy'] = $taxonomy;
      $frontmatter['____'] = NULL;
    }

    if (!empty($media)) {
      $frontmatter['media'] = $media;
      $frontmatter['_____'] = NULL;
    }

    if (!empty($references)) {
      $frontmatter['references'] = $references;
      $frontmatter['______'] = NULL;
    }

    // Campos extra (no clasificados)
    foreach ($extra as $key => $val) {
      $frontmatter[$key] = $val;
    }

    // Translation reference
    $frontmatter['translation_of'] = $this->getTranslationOf($entity);

    // --- Generar YAML limpio ---
    $yaml = $this->buildCleanYaml($frontmatter);

    // --- Cuerpo en Markdown ---
    $body = '';
    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $html = $entity->get('body')->value;
      $body = $this->htmlToMarkdown($html);
    }

    return "---\n" . $yaml . "---\n\n" . $body;
  }

  /**
   * Normaliza el valor de un campo a un formato limpio y legible.
   */
  protected function normalizeField($field, FieldDefinitionInterface $definition): mixed {
    $field_type = $definition->getType();
    $items = $field->getValue();
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
          $normalized[] = $item['value'] ?? NULL;
          break;

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
            'url' => $item['uri'] ?? NULL,
            'title' => $item['title'] ?? NULL,
          ];
          break;

        case 'image':
          $file = \Drupal::service('entity_type.manager')
            ->getStorage('file')
            ->load($item['target_id'] ?? 0);
          $normalized[] = $file ? basename($file->getFileUri()) : NULL;
          break;

        case 'file':
          $file = \Drupal::service('entity_type.manager')
            ->getStorage('file')
            ->load($item['target_id'] ?? 0);
          $normalized[] = $file ? basename($file->getFileUri()) : NULL;
          break;

        case 'entity_reference':
          $target_type = $definition->getSetting('target_type');
          $target_id = $item['target_id'] ?? NULL;
          if ($target_id && $target_type === 'taxonomy_term') {
            $term = \Drupal::service('entity_type.manager')
              ->getStorage('taxonomy_term')
              ->load($target_id);
            $normalized[] = $term ? $term->label() : $target_id;
          }
          elseif ($target_id && $target_type === 'node') {
            $node = \Drupal::service('entity_type.manager')
              ->getStorage('node')
              ->load($target_id);
            $normalized[] = $node ? $this->getSlug($node) : $target_id;
          }
          else {
            $normalized[] = $target_id;
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          // No incluir body aquí, se gestiona por separado
          $normalized[] = $item['value'] ?? NULL;
          break;

        default:
          // Fallback: intentar extraer 'value' o devolver el item completo
          $normalized[] = $item['value'] ?? (count($item) === 1 ? reset($item) : $item);
      }
    }

    // Si no es múltiple y solo hay un valor, devolver escalar
    if (!$is_multiple && count($normalized) === 1) {
      return $normalized[0];
    }

    return $normalized ?: NULL;
  }

  /**
   * Obtiene el slug/alias de path de la entidad.
   */
  protected function getSlug(EntityInterface $entity): string {
    if ($entity->hasField('path') && !$entity->get('path')->isEmpty()) {
      $alias = $entity->get('path')->alias;
      if ($alias) {
        // Extraer la última parte del alias como slug
        return ltrim(basename($alias), '/');
      }
    }
    return 'node-' . $entity->id();
  }

  /**
   * Obtiene el path alias completo de la entidad.
   */
  protected function getPathAlias(EntityInterface $entity): ?string {
    if ($entity->hasField('path') && !$entity->get('path')->isEmpty()) {
      $alias = $entity->get('path')->alias;
      if ($alias) {
        return $alias;
      }
    }
    return '/node/' . $entity->id();
  }

  /**
   * Obtiene la referencia de traducción si la entidad es una traducción.
   */
  protected function getTranslationOf(EntityInterface $entity): ?string {
    // isDefaultTranslation() returns FALSE when this is a non-default translation.
    if (!$entity->isDefaultTranslation()) {
      $original = $entity->getUntranslated();
      return $this->shortenUuid($original->uuid());
    }
    return NULL;
  }

  /**
   * Acorta un UUID a los primeros 8 caracteres para legibilidad.
   */
  protected function shortenUuid(string $uuid): string {
    return substr(str_replace('-', '', $uuid), 0, 8);
  }

  /**
   * Convierte HTML básico a Markdown.
   * Para producción se recomienda usar league/html-to-markdown.
   */
  protected function htmlToMarkdown(string $html): string {
    // Si está disponible league/html-to-markdown, usarlo
    if (class_exists('\League\HTMLToMarkdown\HtmlConverter')) {
      $converter = new \League\HTMLToMarkdown\HtmlConverter([
        'strip_tags' => FALSE,
        'bold_style' => '**',
        'italic_style' => '_',
      ]);
      return $converter->convert($html);
    }

    // Fallback: conversiones básicas
    $markdown = $html;

    // Headings
    for ($i = 6; $i >= 1; $i--) {
      $hashes = str_repeat('#', $i);
      $markdown = preg_replace('/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/is', $hashes . ' $1', $markdown);
    }

    // Bold / italic
    $markdown = preg_replace('/<strong[^>]*>(.*?)<\/strong>/is', '**$1**', $markdown);
    $markdown = preg_replace('/<b[^>]*>(.*?)<\/b>/is', '**$1**', $markdown);
    $markdown = preg_replace('/<em[^>]*>(.*?)<\/em>/is', '_$1_', $markdown);
    $markdown = preg_replace('/<i[^>]*>(.*?)<\/i>/is', '_$1_', $markdown);

    // Links
    $markdown = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $markdown);

    // Lists
    $markdown = preg_replace('/<li[^>]*>(.*?)<\/li>/is', '- $1', $markdown);
    $markdown = preg_replace('/<\/?[ou]l[^>]*>/is', '', $markdown);

    // Párrafos y saltos
    $markdown = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $markdown);
    $markdown = preg_replace('/<br\s*\/?>/i', "\n", $markdown);

    // Limpiar tags restantes y decodificar entidades
    $markdown = strip_tags($markdown);
    $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim($markdown) . "\n";
  }

  /**
   * Construye el YAML limpio gestionando las claves "línea en blanco".
   * Las claves vacías ('', '_', '__', etc.) se renderizan como líneas vacías.
   */
  protected function buildCleanYaml(array $frontmatter): string {
    $lines = [];

    foreach ($frontmatter as $key => $value) {
      // Claves ficticias para insertar líneas en blanco
      if (preg_match('/^_+$/', $key) || $key === '') {
        $lines[] = '';
        continue;
      }

      if ($value === NULL) {
        $lines[] = $key . ': null';
        continue;
      }

      if (is_bool($value)) {
        $lines[] = $key . ': ' . ($value ? 'true' : 'false');
        continue;
      }

      if (is_array($value)) {
        $yaml_chunk = Yaml::dump([$key => $value], 4, 2, Yaml::DUMP_NULL_AS_TILDE);
        // Eliminar trailing newline para controlar nosotros el formato
        $lines[] = rtrim($yaml_chunk);
        continue;
      }

      // Escalar: string, int, float
      $yaml_chunk = Yaml::dump([$key => $value], 1, 2);
      $lines[] = rtrim($yaml_chunk);
    }

    return implode("\n", $lines) . "\n";
  }

}