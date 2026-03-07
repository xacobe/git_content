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
      'file'             => $this->importFile_($frontmatter),
      'user'             => $this->importUser($frontmatter),
      'taxonomy_term'    => $this->importTerm($frontmatter, $body),
      'media'            => $this->importMedia($frontmatter, $body),
      'block_content'    => $this->importBlockContent($frontmatter, $body),
      'menu_link_content'=> $this->importMenuLink($frontmatter, $body),
      default            => $this->importNode($frontmatter, $body),
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
      $node = Node::create([
        'type'     => $bundle,
        'langcode' => $langcode,
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : \Drupal::service('uuid')->generate(),
      ]);
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
   * Importa o actualiza una entidad file (solo metadatos).
   *
   * El archivo físico debe existir ya en sites/default/files/.
   * Este método registra o actualiza la entidad en la tabla managed_file.
   * Nombre del método: importFile_ para evitar colisión con importFile().
   */
  protected function importFile_(array $frontmatter): string {
    $short_uuid = $frontmatter['uuid'] ?? NULL;
    $uri        = $frontmatter['uri'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';

    if (!$uri) {
      throw new \Exception("El frontmatter del file no contiene 'uri'.");
    }

    // Buscar por UUID corto primero, luego por URI como fallback
    $existing = $short_uuid ? $this->findByShortUuidGlobal($short_uuid, 'file') : NULL;

    if (!$existing) {
      $existing_files = $this->entityTypeManager->getStorage('file')
        ->loadByProperties(['uri' => $uri]);
      $existing = !empty($existing_files) ? reset($existing_files) : NULL;
    }

    if ($existing) {
      $file = $existing;
      $operation = 'updated';
    }
    else {
      $file = $this->entityTypeManager->getStorage('file')->create([
        'langcode' => $langcode,
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : \Drupal::service('uuid')->generate(),
      ]);
      $operation = 'imported';
    }

    $file->set('filename', $frontmatter['filename'] ?? basename($uri));
    $file->set('uri', $uri);
    $file->set('filemime', $frontmatter['mime'] ?? 'application/octet-stream');
    $file->set('filesize', (int) ($frontmatter['size'] ?? 0));
    $file->set('status', ($frontmatter['status'] ?? 'permanent') === 'permanent' ? 1 : 0);

    if (!empty($frontmatter['created'])) {
      $file->set('created', $this->parseDate($frontmatter['created']));
    }

    // Resolver propietario por nombre de usuario
    if (!empty($frontmatter['owner'])) {
      $uid = $this->findUserByName($frontmatter['owner']);
      if ($uid) {
        $file->set('uid', $uid);
      }
    }

    $file->save();

    return $operation;
  }

  /**
   * Importa o actualiza un usuario.
   *
   * Si el usuario ya existe (por UUID o por nombre/email) se actualiza.
   * El usuario 1 se omite al importar si ya existe para no sobreescribir
   * credenciales. Las contraseñas nunca se importan; si es un usuario nuevo
   * se genera una contraseña aleatoria que deberá resetearse.
   */
  protected function importUser(array $frontmatter): string {
    $short_uuid = $frontmatter['uuid'] ?? NULL;
    $name       = $frontmatter['name'] ?? NULL;
    $mail       = $frontmatter['mail'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';

    if (!$name) {
      throw new \Exception("El frontmatter del usuario no contiene 'name'.");
    }

    // Buscar existente por UUID, luego por nombre, luego por email
    $existing = $short_uuid ? $this->findByShortUuidGlobal($short_uuid, 'user') : NULL;

    if (!$existing && $name) {
      $users = $this->entityTypeManager->getStorage('user')
        ->loadByProperties(['name' => $name]);
      $existing = !empty($users) ? reset($users) : NULL;
    }

    if (!$existing && $mail) {
      $users = $this->entityTypeManager->getStorage('user')
        ->loadByProperties(['mail' => $mail]);
      $existing = !empty($users) ? reset($users) : NULL;
    }

    // Si el usuario 1 ya existe, actualizar solo datos no críticos
    if ($existing && (int) $existing->id() === 1) {
      $existing->set('timezone', $frontmatter['timezone'] ?? 'UTC');
      $existing->save();
      return 'updated';
    }

    if ($existing) {
      $user = $existing;
      $operation = 'updated';
    }
    else {
      $user = $this->entityTypeManager->getStorage('user')->create([
        'langcode' => $langcode,
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : \Drupal::service('uuid')->generate(),
        // Contraseña aleatoria segura; debe resetearse manualmente
        'pass'     => \Drupal::service('password_generator')->generate(20),
      ]);
      $operation = 'imported';
    }

    $user->set('name', $name);
    $user->set('status', ($frontmatter['status'] ?? 'active') === 'active' ? 1 : 0);
    $user->set('langcode', $langcode);
    $user->set('preferred_langcode', $langcode);
    $user->set('timezone', $frontmatter['timezone'] ?? 'UTC');

    if ($mail) {
      $user->set('mail', $mail);
      $user->set('init', $mail);
    }

    if (!empty($frontmatter['created'])) {
      $user->set('created', $this->parseDate($frontmatter['created']));
    }

    // Asignar roles (deben existir ya en config)
    if (!empty($frontmatter['roles']) && is_array($frontmatter['roles'])) {
      foreach ($frontmatter['roles'] as $role) {
        $user->addRole($role);
      }
    }

    // Campos extra del perfil
    $definitions = $this->fieldDiscovery->getFields('user', 'user');
    $this->populateDynamicFields($user, $frontmatter, $definitions);

    $user->save();

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
        'vid'              => $vid,
        'langcode'         => $langcode,
        'default_langcode' => 1,
        'uuid'             => $short_uuid ? $this->expandShortUuid($short_uuid) : \Drupal::service('uuid')->generate(),
      ]);
      $operation = 'imported';
    }

    // 'name' es obligatorio; usar el slug como fallback si está vacío.
    $name = !empty($frontmatter['name']) ? $frontmatter['name'] : ucfirst(str_replace('-', ' ', $frontmatter['slug'] ?? 'term'));
    $term->set('name', $name);
    $term->set('status', ($frontmatter['status'] ?? 'published') === 'published' ? 1 : 0);
    // default_langcode debe ser explícito; Drupal no lo inicializa en taxonomy_term.
    $term->set('default_langcode', 1);

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
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : \Drupal::service('uuid')->generate(),
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

  /**
   * Importa o actualiza un block_content (bloque de contenido personalizado).
   */
  protected function importBlockContent(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    if (!$bundle) {
      throw new \Exception("El frontmatter del block_content no contiene 'bundle'.");
    }

    $existing = $short_uuid ? $this->findByShortUuidGlobal($short_uuid, 'block_content') : NULL;

    if ($existing) {
      $block = $existing->hasTranslation($langcode)
        ? $existing->getTranslation($langcode)
        : $existing->addTranslation($langcode);
      $operation = 'updated';
    }
    else {
      $block = $this->entityTypeManager->getStorage('block_content')->create([
        'type'             => $bundle,
        'langcode'         => $langcode,
        'default_langcode' => 1,
        'uuid'             => $short_uuid ? $this->expandShortUuid($short_uuid) : \Drupal::service('uuid')->generate(),
      ]);
      $operation = 'imported';
    }

    $block->set('info', $frontmatter['title'] ?? 'Sin título');
    $block->set('status', ($frontmatter['status'] ?? 'draft') === 'published' ? 1 : 0);
    $block->set('default_langcode', 1);

    if ($block->hasField('body') && !empty($body)) {
      $block->set('body', [
        'value'  => $this->serializer->markdownToHtml($body),
        'format' => 'basic_html',
      ]);
    }

    $definitions = $this->fieldDiscovery->getFields('block_content', $bundle);
    $this->populateDynamicFields($block, $frontmatter, $definitions);

    $block->save();

    return $operation;
  }

  /**
   * Importa o actualiza un menu_link_content.
   *
   * Los enlaces se importan respetando la jerarquía: los padres antes que los
   * hijos. El método importAllMenuLinks() de importAll() ya ordena los archivos
   * por nombre (que lleva el peso como prefijo), pero la jerarquía se resuelve
   * aquí mediante un mapa uuid_corto → plugin_id real.
   */
  protected function importMenuLink(array $frontmatter, string $body): string {
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;
    $menu_name  = $frontmatter['menu'] ?? 'main';

    $existing = $short_uuid
      ? $this->findByShortUuid($short_uuid, 'menu_link_content', $menu_name)
      : NULL;

    if ($existing) {
      $link = $existing->hasTranslation($langcode)
        ? $existing->getTranslation($langcode)
        : $existing->addTranslation($langcode);
      $operation = 'updated';
    }
    else {
      $link = $this->entityTypeManager->getStorage('menu_link_content')->create([
        'langcode'  => $langcode,
        'menu_name' => $menu_name,
        'uuid'      => $short_uuid ? $this->expandShortUuid($short_uuid) : \Drupal::service('uuid')->generate(),
      ]);
      $operation = 'imported';
    }

    $link->set('title', $frontmatter['title'] ?? '');
    $link->set('link', ['uri' => $frontmatter['url'] ?? 'internal:/']);
    $link->set('weight', (int) ($frontmatter['weight'] ?? 0));
    $link->set('expanded', (bool) ($frontmatter['expanded'] ?? FALSE));
    $link->set('enabled', (bool) ($frontmatter['enabled'] ?? TRUE));

    // Resolver el padre: el frontmatter guarda el UUID corto del padre.
    // Lo resolvemos contra el mapa que construye importAll().
    $parent_ref = $frontmatter['parent'] ?? NULL;
    if ($parent_ref) {
      $parent_plugin_id = $this->resolveMenuLinkParent($parent_ref, $menu_name);
      if ($parent_plugin_id) {
        $link->set('parent', $parent_plugin_id);
      }
    }

    if (!empty($body) && $link->hasField('description')) {
      $link->set('description', trim($body));
    }

    $link->save();

    // Registrar el plugin_id real en el mapa para que los hijos puedan usarlo
    if ($short_uuid) {
      $this->menuLinkUuidMap[$short_uuid] = 'menu_link_content:' . $link->uuid();
    }

    return $operation;
  }

  /**
   * Mapa temporal uuid_corto → plugin_id real, usado durante la importación.
   * Se rellena conforme se van importando los enlaces.
   */
  protected array $menuLinkUuidMap = [];

  /**
   * Resuelve el plugin_id del padre a partir de su UUID corto.
   * Si el padre es un plugin de otro módulo, lo devuelve tal cual.
   */
  protected function resolveMenuLinkParent(string $parent_ref, string $menu_name): ?string {
    // Si ya está en el mapa (importado en esta sesión)
    if (isset($this->menuLinkUuidMap[$parent_ref])) {
      return $this->menuLinkUuidMap[$parent_ref];
    }

    // Si es un plugin externo (no menu_link_content), devolverlo tal cual
    if (!preg_match('/^[a-f0-9]{8}$/', $parent_ref)) {
      return $parent_ref;
    }

    // Buscar en base de datos por UUID corto
    $existing = $this->findByShortUuid($parent_ref, 'menu_link_content', $menu_name);
    if ($existing) {
      return 'menu_link_content:' . $existing->uuid();
    }

    return NULL;
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
      // block_content system fields
      'id', 'revision_id', 'revision_created', 'revision_user', 'info', 'reusable',
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
      'node'             => 'type',
      'taxonomy_term'    => 'vid',
      'media'            => 'bundle',
      'block_content'    => 'type',
      'menu_link_content'=> 'menu_name',
      default            => 'type',
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

  /**
   * Busca una entidad por UUID corto sin filtrar por bundle.
   * Más seguro cuando el bundle puede haber cambiado o no es fiable.
   */
  protected function findByShortUuidGlobal(string $short_uuid, string $entity_type): mixed {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();

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


  /**
   * Intenta reconstruir un UUID completo a partir de un UUID corto (8 chars).
   *
   * El UUID corto es los primeros 8 caracteres del UUID sin guiones.
   * No es posible recuperar el UUID original exacto, así que generamos uno
   * nuevo que empieza con esos 8 caracteres para mantener trazabilidad.
   * Si el frontmatter tiene el UUID completo (32 chars sin guiones) lo usamos tal cual.
   */
  protected function expandShortUuid(string $short): string {
    $clean = str_replace('-', '', $short);

    // Si ya es un UUID completo sin guiones (32 chars), formatear con guiones
    if (strlen($clean) === 32) {
      return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($clean, 4));
    }

    // UUID corto: completar con ceros y generar formato UUID válido
    $padded = str_pad($clean, 32, '0');
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($padded, 4));
  }


  /**
   * Busca un usuario por nombre de cuenta.
   */
  protected function findUserByName(string $name): ?int {
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['name' => $name]);
    return !empty($users) ? (int) reset($users)->id() : NULL;
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