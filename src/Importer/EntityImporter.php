<?php

namespace Drupal\git_content\Importer;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\Yaml\Yaml;

/**
 * Importa archivos Markdown con frontmatter YAML a nodos de Drupal.
 *
 * Proceso:
 *  1. Parsea el frontmatter YAML y el cuerpo Markdown del archivo.
 *  2. Busca si ya existe un nodo con el mismo UUID (actualización) o crea uno nuevo.
 *  3. Desnormaliza cada campo del frontmatter al formato que espera Drupal.
 *  4. Guarda el nodo y, si aplica, su alias de path.
 */
class EntityImporter {

  protected FieldDiscovery $fieldDiscovery;
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\git_content\Discovery\FieldDiscovery $fieldDiscovery
   *   Servicio para descubrir campos.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Servicio entity_type.manager.
   */
  public function __construct(
    FieldDiscovery $fieldDiscovery,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->fieldDiscovery = $fieldDiscovery;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Importa todos los archivos .md de la carpeta content_export.
   *
   * @return array
   *   Resumen de operaciones: ['imported' => [...], 'updated' => [...], 'errors' => [...]]
   */
  public function importAll(): array {
    $import_dir = DRUPAL_ROOT . '/content_export';
    $result = ['imported' => [], 'updated' => [], 'errors' => []];

    if (!is_dir($import_dir)) {
      $result['errors'][] = 'El directorio content_export no existe.';
      return $result;
    }

    // Recorrer subdirectorios por tipo de contenido
    $type_dirs = glob($import_dir . '/*', GLOB_ONLYDIR);
    foreach ($type_dirs as $type_dir) {
      $files = glob($type_dir . '/*.md');
      foreach ($files as $filepath) {
        try {
          $op = $this->importFile($filepath);
          $result[$op][] = basename($filepath);
        }
        catch (\Exception $e) {
          $result['errors'][] = basename($filepath) . ': ' . $e->getMessage();
        }
      }
    }

    return $result;
  }

  /**
   * Importa un único archivo Markdown.
   *
   * @param string $filepath
   *   Ruta absoluta al archivo .md.
   *
   * @return string
   *   'imported' si es nuevo, 'updated' si ya existía.
   *
   * @throws \Exception
   */
  public function importFile(string $filepath): string {
    if (!file_exists($filepath)) {
      throw new \Exception("Archivo no encontrado: $filepath");
    }

    $raw = file_get_contents($filepath);
    [$frontmatter, $body_markdown] = $this->parseMarkdownFile($raw);

    if (empty($frontmatter['type'])) {
      throw new \Exception("El frontmatter no contiene el campo 'type'.");
    }

    $bundle = $frontmatter['type'];
    $langcode = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    // Buscar nodo existente por UUID corto
    $existing_node = $short_uuid ? $this->findNodeByShortUuid($short_uuid, $bundle) : NULL;

    if ($existing_node) {
      // Si el nodo existe pero no tiene esta traducción, añadirla
      if ($existing_node->hasTranslation($langcode)) {
        $node = $existing_node->getTranslation($langcode);
      }
      else {
        $node = $existing_node->addTranslation($langcode);
      }
      $operation = 'updated';
    }
    else {
      // Crear nodo nuevo
      $node = Node::create([
        'type' => $bundle,
        'langcode' => $langcode,
      ]);
      $operation = 'imported';
    }

    // Poblar campos base
    $node->set('title', $frontmatter['title'] ?? 'Sin título');
    $node->set('status', ($frontmatter['status'] ?? 'draft') === 'published' ? 1 : 0);

    if (!empty($frontmatter['created'])) {
      $node->set('created', $this->parseDate($frontmatter['created']));
    }
    if (!empty($frontmatter['changed'])) {
      $node->set('changed', $this->parseDate($frontmatter['changed']));
    }

    // Cuerpo: convertir Markdown a HTML
    if ($node->hasField('body') && !empty($body_markdown)) {
      $node->set('body', [
        'value' => $this->markdownToHtml($body_markdown),
        'format' => 'basic_html',
      ]);
    }

    // Campos dinámicos (extra, taxonomy, media, references)
    $field_definitions = $this->fieldDiscovery->getFields('node', $bundle);
    $this->populateDynamicFields($node, $frontmatter, $field_definitions);

    $node->save();

    // Path alias
    if (!empty($frontmatter['path'])) {
      $this->savePathAlias($node, $frontmatter['path'], $langcode);
    }

    return $operation;
  }

  // ---------------------------------------------------------------------------
  // Parseo del archivo Markdown
  // ---------------------------------------------------------------------------

  /**
   * Parsea un archivo Markdown con frontmatter YAML.
   *
   * @param string $raw
   *   Contenido crudo del archivo.
   *
   * @return array
   *   [frontmatter array, body string]
   *
   * @throws \Exception
   */
  protected function parseMarkdownFile(string $raw): array {
    // El frontmatter está delimitado por --- al inicio y --- al final
    if (!str_starts_with(ltrim($raw), '---')) {
      throw new \Exception('El archivo no tiene frontmatter YAML válido (falta el delimitador ---).');
    }

    // Extraer entre los dos ---
    $pattern = '/^---\s*\n(.*?)\n---\s*\n?(.*)/s';
    if (!preg_match($pattern, ltrim($raw), $matches)) {
      throw new \Exception('No se pudo parsear el frontmatter YAML.');
    }

    $yaml_raw = $matches[1];
    $body = trim($matches[2]);

    // Limpiar líneas en blanco ficticias del YAML (claves '_', '__', etc.)
    // que el exportador inserta para mejorar la legibilidad
    $yaml_clean = preg_replace('/^_+:\s*(null|~)?\s*$/m', '', $yaml_raw);
    $yaml_clean = preg_replace('/^\s*:\s*(null|~)?\s*$/m', '', $yaml_clean);

    $frontmatter = Yaml::parse($yaml_clean) ?? [];

    // Aplanar grupos: taxonomy.*, media.*, references.* al nivel de campo real
    $frontmatter = $this->flattenGroups($frontmatter);

    return [$frontmatter, $body];
  }

  /**
   * Aplana los grupos taxonomy, media y references al nivel raíz del frontmatter.
   * El importador trabaja campo a campo, no con los grupos del exportador.
   */
  protected function flattenGroups(array $frontmatter): array {
    foreach (['taxonomy', 'media', 'references'] as $group) {
      if (isset($frontmatter[$group]) && is_array($frontmatter[$group])) {
        foreach ($frontmatter[$group] as $key => $value) {
          // Evitar colisiones: solo añadir si no existe ya
          if (!isset($frontmatter[$key])) {
            $frontmatter[$key] = $value;
          }
        }
        unset($frontmatter[$group]);
      }
    }
    return $frontmatter;
  }

  // ---------------------------------------------------------------------------
  // Población de campos dinámicos
  // ---------------------------------------------------------------------------

  /**
   * Popula los campos dinámicos de un nodo a partir del frontmatter.
   */
  protected function populateDynamicFields(
    Node $node,
    array $frontmatter,
    array $field_definitions
  ): void {
    // Campos que ya se gestionan manualmente, ignorar en el bucle
    $skip = [
      'uuid', 'type', 'lang', 'langcode', 'status', 'created', 'changed',
      'title', 'slug', 'path', 'translation_of',
      'nid', 'vid', 'revision_log', 'revision_default',
      'revision_translation_affected', 'default_langcode',
      'content_translation_source', 'content_translation_outdated',
      // Claves vacías ficticias
      '', '_', '__', '___', '____', '_____', '______',
    ];

    foreach ($field_definitions as $field_name => $definition) {
      if (in_array($field_name, $skip)) {
        continue;
      }
      if (!$node->hasField($field_name)) {
        continue;
      }

      // Buscar el valor en el frontmatter:
      // puede estar directamente (campo extra) o dentro de taxonomy/media/references
      // (ya aplanados por flattenGroups). También puede estar bajo el nombre del
      // vocabulario en lugar del nombre del campo.
      $value = $frontmatter[$field_name] ?? NULL;

      // Para campos de taxonomía el exportador usa el nombre del vocabulario
      // como clave. Intentar buscarlo también.
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
        $node->set($field_name, $denormalized);
      }
    }
  }

  /**
   * Desnormaliza un valor del frontmatter al formato que espera Drupal.
   */
  protected function denormalizeField(mixed $value, FieldDefinitionInterface $definition): mixed {
    $field_type = $definition->getType();

    // Normalizar siempre a array para procesar de forma uniforme
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
          // Acepta 'YYYY-MM-DD' o 'YYYY-MM-DDTHH:MM:SS'
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
          if (is_array($item)) {
            $result[] = [
              'uri' => $item['url'] ?? '',
              'title' => $item['title'] ?? '',
            ];
          }
          else {
            $result[] = ['uri' => (string) $item, 'title' => ''];
          }
          break;

        case 'image':
        case 'file':
          // El valor es el nombre del archivo; buscar en managed files
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
            'value' => is_string($item) ? $this->markdownToHtml($item) : (string) $item,
            'format' => 'basic_html',
          ];
          break;

        default:
          // Fallback genérico
          if (is_scalar($item)) {
            $result[] = ['value' => $item];
          }
          else {
            $result[] = $item;
          }
      }
    }

    if (empty($result)) {
      return NULL;
    }

    // Si la cardinalidad es 1, devolver el primer elemento directamente
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
    if ($cardinality === 1) {
      return $result[0];
    }

    return $result;
  }

  // ---------------------------------------------------------------------------
  // Resolución de referencias
  // ---------------------------------------------------------------------------

  /**
   * Resuelve un valor de referencia a un entity ID.
   *
   * @param mixed $value
   *   Valor del frontmatter: label de término, slug de nodo, o ID numérico.
   * @param string $target_type
   *   Tipo de entidad referenciada ('taxonomy_term', 'node', etc.).
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   Definición del campo.
   *
   * @return int|null
   *   El ID de la entidad referenciada, o NULL si no se encuentra.
   */
  protected function resolveEntityReference(
    mixed $value,
    string $target_type,
    FieldDefinitionInterface $definition
  ): ?int {
    if ($value === NULL) {
      return NULL;
    }

    if ($target_type === 'taxonomy_term') {
      return $this->findTermByLabel((string) $value, $definition);
    }

    if ($target_type === 'node') {
      // Puede ser un slug o un ID numérico
      if (is_numeric($value)) {
        return (int) $value;
      }
      return $this->findNodeBySlug((string) $value);
    }

    // Fallback numérico
    return is_numeric($value) ? (int) $value : NULL;
  }

  /**
   * Busca un término de taxonomía por su label dentro de los vocabularios del campo.
   */
  protected function findTermByLabel(string $label, FieldDefinitionInterface $definition): ?int {
    $vocab_bundles = $definition->getSetting('handler_settings')['target_bundles'] ?? [];

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $label);

    if (!empty($vocab_bundles)) {
      $query->condition('vid', array_keys($vocab_bundles), 'IN');
    }

    $tids = $query->execute();

    if (!empty($tids)) {
      return (int) reset($tids);
    }

    // Si no existe, crear el término automáticamente en el primer vocabulario
    if (!empty($vocab_bundles)) {
      $vid = array_key_first($vocab_bundles);
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
        'vid' => $vid,
        'name' => $label,
      ]);
      $term->save();
      return (int) $term->id();
    }

    return NULL;
  }

  /**
   * Busca un nodo por su slug (alias de path).
   */
  protected function findNodeBySlug(string $slug): ?int {
    // Buscar en path_alias
    $alias_storage = $this->entityTypeManager->getStorage('path_alias');
    $aliases = $alias_storage->loadByProperties(['alias' => '/' . ltrim($slug, '/')]);

    foreach ($aliases as $alias) {
      $path = $alias->getPath();
      if (preg_match('/^\/node\/(\d+)$/', $path, $matches)) {
        return (int) $matches[1];
      }
    }

    return NULL;
  }

  /**
   * Busca un nodo por UUID corto (primeros 8 chars sin guiones).
   */
  protected function findNodeByShortUuid(string $short_uuid, string $bundle): ?Node {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->execute();

    if (empty($nids)) {
      return NULL;
    }

    $nodes = $storage->loadMultiple($nids);
    foreach ($nodes as $node) {
      $node_short = substr(str_replace('-', '', $node->uuid()), 0, 8);
      if ($node_short === $short_uuid) {
        return $node;
      }
    }

    return NULL;
  }

  /**
   * Busca un archivo gestionado por su nombre de archivo.
   */
  protected function findFileByName(string $filename): ?int {
    $files = $this->entityTypeManager->getStorage('file')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('filename', $filename)
      ->execute();

    return !empty($files) ? (int) reset($files) : NULL;
  }

  // ---------------------------------------------------------------------------
  // Path alias
  // ---------------------------------------------------------------------------

  /**
   * Guarda o actualiza el alias de path de un nodo.
   */
  protected function savePathAlias(Node $node, string $alias, string $langcode): void {
    $path = '/node/' . $node->id();
    $alias_storage = $this->entityTypeManager->getStorage('path_alias');

    // Buscar alias existente para este path
    $existing = $alias_storage->loadByProperties([
      'path' => $path,
      'langcode' => $langcode,
    ]);

    if (!empty($existing)) {
      $alias_entity = reset($existing);
      $alias_entity->set('alias', $alias);
      $alias_entity->save();
    }
    else {
      $alias_entity = $alias_storage->create([
        'path' => $path,
        'alias' => $alias,
        'langcode' => $langcode,
      ]);
      $alias_entity->save();
    }
  }

  // ---------------------------------------------------------------------------
  // Utilidades
  // ---------------------------------------------------------------------------

  /**
   * Convierte una fecha en string ('YYYY-MM-DD' o timestamp) a timestamp Unix.
   */
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

  /**
   * Convierte Markdown a HTML.
   * Usa league/commonmark si está disponible, si no un fallback básico.
   */
  protected function markdownToHtml(string $markdown): string {
    if (class_exists('\League\CommonMark\CommonMarkConverter')) {
      $converter = new \League\CommonMark\CommonMarkConverter();
      return (string) $converter->convert($markdown);
    }

    // Fallback básico
    $html = htmlspecialchars($markdown, ENT_NOQUOTES, 'UTF-8');
    $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/_(.+?)_/s', '<em>$1</em>', $html);
    $html = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $html);
    $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);
    // Párrafos: líneas separadas por línea en blanco
    $paragraphs = preg_split('/\n{2,}/', trim($html));
    $html = implode("\n", array_map(fn($p) => '<p>' . trim($p) . '</p>', $paragraphs));

    return $html;
  }

  /**
   * Determina si un array es asociativo.
   */
  protected function isAssoc(array $arr): bool {
    if (empty($arr)) {
      return FALSE;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
  }

}