<?php

namespace Drupal\git_content\Importer;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\node\Entity\Node;

/**
 * Importa archivos Markdown con frontmatter YAML a entidades de Drupal.
 *
 * Detecta automáticamente el tipo de entidad a partir del campo 'type' del
 * frontmatter y delega en el método de importación correspondiente.
 *
 * Tipos soportados:
 *   - Nodos:             type: {bundle}  (article, page, project…)
 *   - Términos:          type: taxonomy_term
 *   - Media:             type: media
 */
class MarkdownImporter {

  protected FieldDiscovery $fieldDiscovery;
  protected MarkdownSerializer $serializer;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    FieldDiscovery $fieldDiscovery,
    MarkdownSerializer $serializer,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->fieldDiscovery    = $fieldDiscovery;
    $this->serializer        = $serializer;
    $this->entityTypeManager = $entityTypeManager;
  }

  // ---------------------------------------------------------------------------
  // Importación masiva
  // ---------------------------------------------------------------------------

  /**
   * Importa todos los archivos .md de content_export/.
   *
   * @return array{imported: string[], updated: string[], errors: string[]}
   */
  public function importAll(): array {
    $import_dir = DRUPAL_ROOT . '/content_export';
    $result = ['imported' => [], 'updated' => [], 'errors' => []];

    if (!is_dir($import_dir)) {
      $result['errors'][] = 'El directorio content_export no existe.';
      return $result;
    }

    // Recoger todos los .md de forma recursiva
    $files = $this->findMarkdownFiles($import_dir);

    foreach ($files as $filepath) {
      try {
        $op = $this->importFile($filepath);
        $result[$op][] = str_replace(DRUPAL_ROOT . '/content_export/', '', $filepath);
      }
      catch (\Exception $e) {
        $result['errors'][] = basename($filepath) . ': ' . $e->getMessage();
      }
    }

    return $result;
  }

  /**
   * Importa un único archivo Markdown.
   *
   * @return string 'imported' | 'updated'
   * @throws \Exception
   */
  public function importFile(string $filepath): string {
    if (!file_exists($filepath)) {
      throw new \Exception("Archivo no encontrado: $filepath");
    }

    $raw = file_get_contents($filepath);
    $parsed = $this->serializer->deserialize($raw);
    $frontmatter = $this->serializer->flattenGroups($parsed['frontmatter']);
    $body = $parsed['body'];

    $type = $frontmatter['type'] ?? NULL;
    if (!$type) {
      throw new \Exception("El frontmatter no contiene el campo 'type'.");
    }

    return match ($type) {
      'taxonomy_term' => $this->importTerm($frontmatter, $body),
      'media'         => $this->importMedia($frontmatter, $body),
      default         => $this->importNode($frontmatter, $body),
    };
  }

  // ---------------------------------------------------------------------------
  // Importadores por tipo de entidad
  // ---------------------------------------------------------------------------

  /**
   * Importa o actualiza un nodo.
   */
  protected function importNode(array $frontmatter, string $body): string {
    $bundle    = $frontmatter['type'];
    $langcode  = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    $existing = $short_uuid ? $this->findByShortUuid($short_uuid, 'node', $bundle) : NULL;

    if ($existing) {
      $node = $existing->hasTranslation($langcode)
        ? $existing->getTranslation($langcode)
        : $existing->addTranslation($langcode);
      $operation = 'updated';
    }
    else {
      $node = Node::create(['type' => $bundle, 'langcode' => $langcode]);
      $operation = 'imported';
    }

    $node->set('title', $frontmatter['title'] ?? 'Sin título');
    $node->set('status', ($frontmatter['status'] ?? 'draft') === 'published' ? 1 : 0);

    if (!empty($frontmatter['created'])) {
      $node->set('created', $this->parseDate($frontmatter['created']));
    }
    if (!empty($frontmatter['changed'])) {
      $node->set('changed', $this->parseDate($frontmatter['changed']));
    }

    if ($node->hasField('body') && !empty($body)) {
      $node->set('body', [
        'value'  => $this->serializer->markdownToHtml($body),
        'format' => 'basic_html',
      ]);
    }

    $definitions = $this->fieldDiscovery->getFields('node', $bundle);
    $this->populateDynamicFields($node, $frontmatter, $definitions);

    $node->save();

    if (!empty($frontmatter['path'])) {
      $this->savePathAlias($node, $frontmatter['path'], $langcode);
    }

    return $operation;
  }

  /**
   * Importa o actualiza un término de taxonomía.
   */
  protected function importTerm(array $frontmatter, string $body): string {
    $vid       = $frontmatter['vocabulary'] ?? NULL;
    $langcode  = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    if (!$vid) {
      throw new \Exception("El frontmatter del término no contiene 'vocabulary'.");
    }

    $existing = $short_uuid ? $this->findByShortUuid($short_uuid, 'taxonomy_term', $vid) : NULL;

    if ($existing) {
      $term = $existing->hasTranslation($langcode)
        ? $existing->getTranslation($langcode)
        : $existing->addTranslation($langcode);
      $operation = 'updated';
    }
    else {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
        'vid'      => $vid,
        'langcode' => $langcode,
      ]);
      $operation = 'imported';
    }

    $term->set('name', $frontmatter['name'] ?? 'Sin nombre');

    if (isset($frontmatter['weight'])) {
      $term->set('weight', (int) $frontmatter['weight']);
    }

    if (!empty($frontmatter['parent'])) {
      $term->set('parent', [(int) $frontmatter['parent']]);
    }

    if ($term->hasField('description') && !empty($body)) {
      $term->set('description', [
        'value'  => $this->serializer->markdownToHtml($body),
        'format' => 'basic_html',
      ]);
    }

    $definitions = $this->fieldDiscovery->getFields('taxonomy_term', $vid);
    $this->populateDynamicFields($term, $frontmatter, $definitions);

    $term->save();

    return $operation;
  }

  /**
   * Importa o actualiza una entidad media (solo metadatos).
   */
  protected function importMedia(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    if (!$bundle) {
      throw new \Exception("El frontmatter del media no contiene 'bundle'.");
    }

    $existing = $short_uuid ? $this->findByShortUuid($short_uuid, 'media', $bundle) : NULL;

    if ($existing) {
      $media = $existing->hasTranslation($langcode)
        ? $existing->getTranslation($langcode)
        : $existing->addTranslation($langcode);
      $operation = 'updated';
    }
    else {
      $media = $this->entityTypeManager->getStorage('media')->create([
        'bundle'   => $bundle,
        'langcode' => $langcode,
      ]);
      $operation = 'imported';
    }

    $media->set('name', $frontmatter['name'] ?? 'Sin nombre');
    $media->set('status', ($frontmatter['status'] ?? 'draft') === 'published' ? 1 : 0);

    $definitions = $this->fieldDiscovery->getFields('media', $bundle);
    $this->populateDynamicFields($media, $frontmatter, $definitions);

    $media->save();

    return $operation;
  }

  // ---------------------------------------------------------------------------
  // Población de campos dinámicos
  // ---------------------------------------------------------------------------

  protected function populateDynamicFields($entity, array $frontmatter, array $definitions): void {
    $skip = [
      'uuid', 'type', 'bundle', 'vocabulary', 'lang', 'langcode', 'status',
      'created', 'changed', 'title', 'name', 'slug', 'path', 'translation_of',
      'weight', 'parent', 'file',
      'nid', 'vid', 'tid', 'mid', 'revision_log', 'revision_default',
      'revision_translation_affected', 'default_langcode',
      'content_translation_source', 'content_translation_outdated',
    ];

    foreach ($definitions as $field_name => $definition) {
      if (in_array($field_name, $skip)) {
        continue;
      }
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $value = $frontmatter[$field_name] ?? NULL;

      // Para taxonomía, el exportador usa el nombre del vocabulario como clave
      if ($value === NULL && $definition->getType() === 'entity_reference') {
        $target_type = $definition->getSetting('target_type');
        if ($target_type === 'taxonomy_term') {
          $vocab = $definition->getSetting('handler_settings')['target_bundles'] ?? [];
          $vocab_key = !empty($vocab) ? implode('_', array_keys($vocab)) : NULL;
          if ($vocab_key && isset($frontmatter[$vocab_key])) {
            $value = $frontmatter[$vocab_key];
          }
        }
      }

      if ($value === NULL) {
        continue;
      }

      $denormalized = $this->denormalizeField($value, $definition);
      if ($denormalized !== NULL) {
        $entity->set($field_name, $denormalized);
      }
    }
  }

  /**
   * Desnormaliza un valor del frontmatter al formato que espera Drupal.
   */
  protected function denormalizeField(mixed $value, FieldDefinitionInterface $definition): mixed {
    $field_type  = $definition->getType();
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();

    // Normalizar siempre a lista para procesar de forma uniforme
    $values = is_array($value) && !$this->isAssoc($value) ? $value : [$value];
    $result = [];

    foreach ($values as $item) {
      switch ($field_type) {
        case 'string':
        case 'string_long':
        case 'list_string':
          $result[] = ['value' => (string) $item];
          break;

        case 'boolean':
          $result[] = ['value' => (int) (bool) $item];
          break;

        case 'integer':
        case 'list_integer':
          $result[] = ['value' => (int) $item];
          break;

        case 'decimal':
        case 'float':
        case 'list_float':
          $result[] = ['value' => (float) $item];
          break;

        case 'datetime':
          $date = is_string($item) ? $item : NULL;
          if ($date && strlen($date) === 10) {
            $date .= 'T00:00:00';
          }
          $result[] = ['value' => $date];
          break;

        case 'timestamp':
          $result[] = ['value' => $this->parseDate($item)];
          break;

        case 'link':
          $result[] = is_array($item)
            ? ['uri' => $item['url'] ?? '', 'title' => $item['title'] ?? '']
            : ['uri' => (string) $item, 'title' => ''];
          break;

        case 'image':
        case 'file':
          $fid = $this->findFileByName((string) $item);
          if ($fid) {
            $result[] = ['target_id' => $fid];
          }
          break;

        case 'entity_reference':
          $target_type = $definition->getSetting('target_type');
          $tid = $this->resolveEntityReference($item, $target_type, $definition);
          if ($tid !== NULL) {
            $result[] = ['target_id' => $tid];
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $result[] = [
            'value'  => is_string($item) ? $this->serializer->markdownToHtml($item) : (string) $item,
            'format' => 'basic_html',
          ];
          break;

        default:
          $result[] = is_scalar($item) ? ['value' => $item] : $item;
      }
    }

    if (empty($result)) {
      return NULL;
    }

    return $cardinality === 1 ? $result[0] : $result;
  }

  // ---------------------------------------------------------------------------
  // Resolución de referencias
  // ---------------------------------------------------------------------------

  protected function resolveEntityReference(mixed $value, string $target_type, FieldDefinitionInterface $definition): ?int {
    if ($value === NULL) {
      return NULL;
    }
    if ($target_type === 'taxonomy_term') {
      return $this->findTermByLabel((string) $value, $definition);
    }
    if ($target_type === 'node') {
      return is_numeric($value) ? (int) $value : $this->findNodeBySlug((string) $value);
    }
    return is_numeric($value) ? (int) $value : NULL;
  }

  protected function findTermByLabel(string $label, FieldDefinitionInterface $definition): ?int {
    $vocab_bundles = $definition->getSetting('handler_settings')['target_bundles'] ?? [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $query = $storage->getQuery()->accessCheck(FALSE)->condition('name', $label);
    if (!empty($vocab_bundles)) {
      $query->condition('vid', array_keys($vocab_bundles), 'IN');
    }
    $tids = $query->execute();

    if (!empty($tids)) {
      return (int) reset($tids);
    }

    // Crear el término si no existe
    if (!empty($vocab_bundles)) {
      $term = $storage->create(['vid' => array_key_first($vocab_bundles), 'name' => $label]);
      $term->save();
      return (int) $term->id();
    }

    return NULL;
  }

  protected function findNodeBySlug(string $slug): ?int {
    $aliases = $this->entityTypeManager->getStorage('path_alias')
      ->loadByProperties(['alias' => '/' . ltrim($slug, '/')]);

    foreach ($aliases as $alias) {
      if (preg_match('/^\/node\/(\d+)$/', $alias->getPath(), $m)) {
        return (int) $m[1];
      }
    }
    return NULL;
  }

  protected function findByShortUuid(string $short_uuid, string $entity_type, string $bundle): mixed {
    $bundle_key = match($entity_type) {
      'node'          => 'type',
      'taxonomy_term' => 'vid',
      'media'         => 'bundle',
      default         => 'type',
    };

    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($bundle_key, $bundle)
      ->execute();

    foreach ($storage->loadMultiple($ids) as $entity) {
      if (substr(str_replace('-', '', $entity->uuid()), 0, 8) === $short_uuid) {
        return $entity;
      }
    }
    return NULL;
  }

  protected function findFileByName(string $filename): ?int {
    $files = $this->entityTypeManager->getStorage('file')
      ->getQuery()->accessCheck(FALSE)->condition('filename', $filename)->execute();
    return !empty($files) ? (int) reset($files) : NULL;
  }

  // ---------------------------------------------------------------------------
  // Path alias
  // ---------------------------------------------------------------------------

  protected function savePathAlias($entity, string $alias, string $langcode): void {
    $path = '/node/' . $entity->id();
    $storage = $this->entityTypeManager->getStorage('path_alias');

    $existing = $storage->loadByProperties(['path' => $path, 'langcode' => $langcode]);

    if (!empty($existing)) {
      $alias_entity = reset($existing);
      $alias_entity->set('alias', $alias);
      $alias_entity->save();
    }
    else {
      $storage->create(['path' => $path, 'alias' => $alias, 'langcode' => $langcode])->save();
    }
  }

  // ---------------------------------------------------------------------------
  // Utilidades
  // ---------------------------------------------------------------------------

  protected function parseDate(mixed $date): int {
    if (is_int($date) || is_numeric($date)) {
      return (int) $date;
    }
    if (is_string($date)) {
      $ts = strtotime($date);
      return $ts !== FALSE ? $ts : \Drupal::time()->getCurrentTime();
    }
    return \Drupal::time()->getCurrentTime();
  }

  protected function isAssoc(array $arr): bool {
    return !empty($arr) && array_keys($arr) !== range(0, count($arr) - 1);
  }

  /**
   * Encuentra todos los archivos .md de forma recursiva en un directorio.
   *
   * @return string[]
   */
  protected function findMarkdownFiles(string $dir): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'md') {
        $files[] = $file->getRealPath();
      }
    }
    return $files;
  }

}
