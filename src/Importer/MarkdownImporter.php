<?php

namespace Drupal\git_content\Importer;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\ChecksumTrait;
use Drupal\git_content\Utility\ManagedFields;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\Entity\Node;
use Psr\Log\LoggerInterface;

/**
 * Import Markdown files with YAML frontmatter into Drupal entities.
 *
 * Automatically detects the entity type from the 'type' field in the
 * frontmatter and delegates to the appropriate import method.
 *
 * Supported types:
 *   - Nodes:             type: {bundle}  (article, page, project…)
 *   - Taxonomy terms:    type: taxonomy_term
 *   - Media:             type: media
 */
class MarkdownImporter {

  use ChecksumTrait;

  protected FieldDiscovery $fieldDiscovery;
  protected MarkdownSerializer $serializer;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected UuidInterface $uuid;
  protected PasswordGeneratorInterface $passwordGenerator;
  protected TimeInterface $time;
  protected LoggerInterface $logger;
  protected AccountProxyInterface $currentUser;

  public function __construct(
    FieldDiscovery $fieldDiscovery,
    MarkdownSerializer $serializer,
    EntityTypeManagerInterface $entityTypeManager,
    UuidInterface $uuid,
    PasswordGeneratorInterface $passwordGenerator,
    TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
    AccountProxyInterface $currentUser,
  ) {
    $this->fieldDiscovery    = $fieldDiscovery;
    $this->serializer        = $serializer;
    $this->entityTypeManager = $entityTypeManager;
    $this->uuid              = $uuid;
    $this->passwordGenerator = $passwordGenerator;
    $this->time              = $time;
    $this->logger            = $loggerFactory->get('git_content');
    $this->currentUser       = $currentUser;
  }

  // ---------------------------------------------------------------------------
  // Bulk import
  // ---------------------------------------------------------------------------

  /**
   * Import all .md files from content_export/.
   *
   * @return array{imported: string[], updated: string[], errors: string[]}
   */
  public function importAll(): array {
    $import_dir = DRUPAL_ROOT . '/content_export';
    $result = ['imported' => [], 'updated' => [], 'deleted' => [], 'skipped' => [], 'errors' => []];

    if (!is_dir($import_dir)) {
      $result['errors'][] = t('The content_export directory does not exist.');
      return $result;
    }

    // Collect all .md files recursively
    $files = $this->findMarkdownFiles($import_dir);

    // Ensure menu links are processed in weight order (parents before children)
    usort($files, fn($a, $b) => $this->compareImportFiles($a, $b));

    $typeCounts = [];
    $seenUuids = [
      'node' => [],
      'taxonomy_term' => [],
      'media' => [],
      'block_content' => [],
      'file' => [],
      'user' => [],
      'menu_link_content' => [],
    ];

    foreach ($files as $filepath) {
      try {
        $import = $this->importFile($filepath);
        $op = $import['op'];
        $type = $import['type'];
        $entity_type = $import['entity_type'] ?? NULL;
        $uuid = $import['uuid'] ?? NULL;
        $bundle = $import['bundle'] ?? '__all';

        $result[$op][] = str_replace(DRUPAL_ROOT . '/content_export/', '', $filepath);

        if (!isset($typeCounts[$type])) {
          $typeCounts[$type] = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];
        }
        if (isset($typeCounts[$type][$op])) {
          $typeCounts[$type][$op]++;
        }

        if ($entity_type && $uuid) {
          $seenUuids[$entity_type][$bundle][$uuid] = TRUE;
        }
      }
      catch (\Exception $e) {
        $result['errors'][] = basename($filepath) . ': ' . $e->getMessage();
      }
    }

    // Remove Drupal entities that no longer have a corresponding .md file.
    $deleted = $this->cleanupDeletedEntities($seenUuids);
    foreach ($deleted as $deletedItem) {
      $result['deleted'][] = $deletedItem;
      // Count deleted items in the per-type summary if applicable.
      $parts = explode(':', $deletedItem, 2);
      if (count($parts) === 2) {
        $deletedType = trim($parts[0]);
        if (!isset($typeCounts[$deletedType])) {
          $typeCounts[$deletedType] = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];
        }
        $typeCounts[$deletedType]['deleted']++;
      }
    }

    // Log a summary of the import to watchdog.
    $created = count($result['imported']);
    $updated = count($result['updated']);
    $skipped = count($result['skipped']);
    $deleted = count($result['deleted']);
    $errors  = count($result['errors']);

    $parts = [];
    foreach ($typeCounts as $type => $counts) {
      $parts[] = "$type: {$counts['imported']} created, {$counts['updated']} updated, {$counts['skipped']} skipped";
    }
    $summary = implode('; ', $parts);

    $this->logger->notice(
      'Import finished: @summary. Total: @created created, @updated updated, @skipped skipped, @deleted deleted, @errors errors.',
      [
        '@summary' => $summary,
        '@created' => (string) $created,
        '@updated' => (string) $updated,
        '@skipped' => (string) $skipped,
        '@deleted' => (string) $deleted,
        '@errors'  => (string) $errors,
      ]
    );

    return $result;
  }

  /**
   * Import a single Markdown file.
   *
   * @return array{op: string, entity_type: string, type: string, uuid?: string|null, bundle?: string|null}
   *   'op' is one of 'imported', 'updated', 'skipped'.
   *   'entity_type' is the Drupal entity type (node, taxonomy_term, ...).
   *   'type' is the frontmatter.type value used for importing.
   *   'uuid' is the short UUID extracted from the frontmatter (if present).
   *   'bundle' is the bundle/vocab/menu for this type.
   * @throws \Exception
   */
  public function importFile(string $filepath): array {
    if (!file_exists($filepath)) {
      throw new \Exception(t('File not found: @file', ['@file' => $filepath]));
    }

    $raw = file_get_contents($filepath);

    $parsed = $this->serializer->deserialize($raw);
    $frontmatter = $this->serializer->flattenGroups($parsed['frontmatter']);
    $body = $parsed['body'];

    $type = $frontmatter['type'] ?? NULL;
    if (!$type) {
      throw new \Exception(t("The frontmatter is missing the 'type' field."));
    }

    $short_uuid = $frontmatter['uuid'] ?? NULL;
    $entity_type = match ($type) {
      'taxonomy_term'    => 'taxonomy_term',
      'file'             => 'file',
      'user'             => 'user',
      'media'            => 'media',
      'block_content'    => 'block_content',
      'menu_link_content'=> 'menu_link_content',
      default            => 'node',
    };

    $bundle = NULL;
    switch ($entity_type) {
      case 'taxonomy_term':
        $bundle = $frontmatter['vocabulary'] ?? NULL;
        break;
      case 'node':
        $bundle = $type; // for nodes, type == bundle
        break;
      case 'media':
      case 'block_content':
        $bundle = $frontmatter['bundle'] ?? NULL;
        break;
      case 'menu_link_content':
        $bundle = $frontmatter['menu_name'] ?? NULL;
        break;
      default:
        $bundle = NULL;
    }

    // If the file contains a checksum, compare it to avoid reimporting
    // when nothing has changed.
    $checksum = $frontmatter['checksum'] ?? NULL;
    if ($checksum) {
      $computed = $this->computeChecksum($frontmatter, $body);
      if ($computed === $checksum) {
        return ['op' => 'skipped', 'entity_type' => $entity_type, 'type' => $type, 'uuid' => $short_uuid, 'bundle' => $bundle];
      }
    }

    $op = match ($type) {
      'file'             => $this->importFileEntity($frontmatter),
      'user'             => $this->importUser($frontmatter),
      'taxonomy_term'    => $this->importTerm($frontmatter, $body),
      'media'            => $this->importMedia($frontmatter, $body),
      'block_content'    => $this->importBlockContent($frontmatter, $body),
      'menu_link_content'=> $this->importMenuLink($frontmatter, $body),
      default            => $this->importNode($frontmatter, $body),
    };

    return ['op' => $op, 'entity_type' => $entity_type, 'type' => $type, 'uuid' => $short_uuid, 'bundle' => $bundle];
  }

  // ---------------------------------------------------------------------------
  // Per-entity-type importers
  // ---------------------------------------------------------------------------

  /**
   * Compute the canonical checksum used to detect changes.
   *
   * Based on the logical data structure (frontmatter + body), not on the
   * specific YAML representation. This allows stable change detection even
   * when YAML formatting changes.
   */
  protected function computeChecksum(array $frontmatter, string $body): string {
    $fm = $frontmatter;
    unset($fm['checksum']);
    $fm = array_filter($fm, fn($key) => !preg_match('/^_+$/', (string) $key), ARRAY_FILTER_USE_KEY);
    $data = $this->canonicalizeForHash(['frontmatter' => $fm, 'body' => $body]);

    return sha1(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
  }

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
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $node->set('title', $frontmatter['title'] ?? 'Untitled');
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
   * Import or update a file entity (metadata only).
   *
   * The physical file must already exist in sites/default/files/.
   * This method registers or updates the entity in the managed_file table.
   * Method named importFile_ to avoid collision with importFile().
   */
  protected function importFileEntity(array $frontmatter): string {
    $short_uuid = $frontmatter['uuid'] ?? NULL;
    $uri        = $frontmatter['uri'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';

    if (!$uri) {
      throw new \Exception(t("The file frontmatter is missing 'uri'."));
    }

    // Look up by short UUID first, then fall back to URI.
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
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
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

    // Resolve owner by username.
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
   * Import or update a user account.
   *
   * If the user already exists (by UUID, name, or email) it is updated.
   * User 1 is skipped on import if it already exists to avoid overwriting
   * production credentials. Passwords are never imported; new users receive
   * a random password that must be reset manually.
   */
  protected function importUser(array $frontmatter): string {
    $short_uuid = $frontmatter['uuid'] ?? NULL;
    $name       = $frontmatter['name'] ?? NULL;
    $mail       = $frontmatter['mail'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';

    if (!$name) {
      throw new \Exception(t("The user frontmatter is missing 'name'."));
    }

    // Look up by UUID first, then by name, then by email.
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

    // If user 1 already exists, only update non-critical data.
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
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
        // Secure random password; must be reset manually.
        'pass'     => $this->passwordGenerator->generate(20),
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

    // Assign roles (they must already exist in config).
    if (!empty($frontmatter['roles']) && is_array($frontmatter['roles'])) {
      foreach ($frontmatter['roles'] as $role) {
        $user->addRole($role);
      }
    }

    // Extra profile fields.
    $definitions = $this->fieldDiscovery->getFields('user', 'user');
    $this->populateDynamicFields($user, $frontmatter, $definitions);

    $user->save();

    return $operation;
  }

  /**
   * Import or update a taxonomy term.
   */
  protected function importTerm(array $frontmatter, string $body): string {
    $vid       = $frontmatter['vocabulary'] ?? NULL;
    $langcode  = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    if (!$vid) {
      throw new \Exception(t("The term frontmatter is missing 'vocabulary'."));
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
        'uuid'             => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    // 'name' is required; fall back to slug if empty.
    $name = !empty($frontmatter['name']) ? $frontmatter['name'] : ucfirst(str_replace('-', ' ', $frontmatter['slug'] ?? 'term'));
    $term->set('name', $name);
    $term->set('status', ($frontmatter['status'] ?? 'published') === 'published' ? 1 : 0);
    // default_langcode must be set explicitly; Drupal does not initialise it for taxonomy_term.
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
   * Import or update a media entity (metadata only).
   */
  protected function importMedia(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    if (!$bundle) {
      throw new \Exception(t("The media frontmatter is missing 'bundle'."));
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
        'uuid'     => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $media->set('name', $frontmatter['name'] ?? 'Unnamed');
    $media->set('status', ($frontmatter['status'] ?? 'draft') === 'published' ? 1 : 0);

    $definitions = $this->fieldDiscovery->getFields('media', $bundle);
    $this->populateDynamicFields($media, $frontmatter, $definitions);

    $media->save();

    return $operation;
  }

  /**
   * Import or update a custom block (block_content).
   */
  protected function importBlockContent(array $frontmatter, string $body): string {
    $bundle     = $frontmatter['bundle'] ?? NULL;
    $langcode   = $frontmatter['lang'] ?? 'und';
    $short_uuid = $frontmatter['uuid'] ?? NULL;

    if (!$bundle) {
      throw new \Exception(t("The block_content frontmatter is missing 'bundle'."));
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
        'uuid'             => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $block->set('info', $frontmatter['title'] ?? 'Untitled');
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
   * Import or update a menu_link_content entity.
   *
   * Links are imported respecting the hierarchy: parents before children.
   * importAll() already sorts files by weight, but the hierarchy is resolved
   * here using a short-uuid → real plugin_id map.
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
        'uuid'      => $short_uuid ? $this->expandShortUuid($short_uuid) : $this->uuid->generate(),
      ]);
      $operation = 'imported';
    }

    $link->set('title', $frontmatter['title'] ?? '');
    $link->set('link', ['uri' => $frontmatter['url'] ?? 'internal:/']);
    $link->set('weight', (int) ($frontmatter['weight'] ?? 0));
    $link->set('expanded', (bool) ($frontmatter['expanded'] ?? FALSE));
    $link->set('enabled', (bool) ($frontmatter['enabled'] ?? TRUE));

    // Resolve the parent: the frontmatter stores the short UUID of the parent.
    // We resolve it against the map built by importAll().
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

    // Register the real plugin_id in the map so children can resolve it.
    if ($short_uuid) {
      $this->menuLinkUuidMap[$short_uuid] = 'menu_link_content:' . $link->uuid();
    }

    return $operation;
  }

  /**
   * Temporary short-uuid → real plugin_id map, populated during import.
   */
  protected array $menuLinkUuidMap = [];

  /**
   * Resolve the parent plugin_id from its short UUID.
   * If the parent is a plugin from another module, return it as-is.
   */
  protected function resolveMenuLinkParent(string $parent_ref, string $menu_name): ?string {
    // Already in the map (imported in this session).
    if (isset($this->menuLinkUuidMap[$parent_ref])) {
      return $this->menuLinkUuidMap[$parent_ref];
    }

    // External plugin (not menu_link_content): return as-is.
    if (!preg_match('/^[a-f0-9]{8}$/', $parent_ref)) {
      return $parent_ref;
    }

    // Look up in the database by short UUID.
    $existing = $this->findByShortUuid($parent_ref, 'menu_link_content', $menu_name);
    if ($existing) {
      return 'menu_link_content:' . $existing->uuid();
    }

    return NULL;
  }


  // ---------------------------------------------------------------------------
  // Dynamic field population
  // ---------------------------------------------------------------------------

  protected function populateDynamicFields($entity, array $frontmatter, array $definitions): void {
    $skip = [
      ...ManagedFields::CORE,
      // Frontmatter keys handled explicitly before this loop.
      'bundle', 'vocabulary', 'lang', 'name', 'slug', 'translation_of',
      'weight', 'parent', 'file', 'checksum',
    ];

    foreach ($definitions as $field_name => $definition) {
      if (in_array($field_name, $skip)) {
        continue;
      }
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $value = $frontmatter[$field_name] ?? NULL;

      // For taxonomy, the exporter uses the vocabulary name as the key.
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
   * Denormalize a frontmatter value into the format Drupal expects.
   */
  protected function denormalizeField(mixed $value, FieldDefinitionInterface $definition): mixed {
    $field_type  = $definition->getType();
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();

    // Always normalise to a list for uniform processing.
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
  // Reference resolution
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

    // Create the term if it does not exist.
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
   * Find an entity by short UUID without filtering by bundle.
   * Safer when the bundle may have changed or is not reliable.
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
  // Utilities
  // ---------------------------------------------------------------------------

  protected function parseDate(mixed $date): int {
    if (is_int($date) || is_numeric($date)) {
      return (int) $date;
    }
    if (is_string($date)) {
      $ts = strtotime($date);
      return $ts !== FALSE ? $ts : $this->time->getCurrentTime();
    }
    return $this->time->getCurrentTime();
  }

  /**
   * Delete Drupal entities that no longer have a corresponding .md file.
   *
   * @param array $seenUuids
   *   Map of entity_type -> bundle -> short_uuid => TRUE representing the
   *   UUIDs of entities imported/updated in this run.
   *
   * @return string[] List of deleted items (for display in the UI).
   */
  protected function cleanupDeletedEntities(array $seenUuids): array {
    $deleted = [];

    foreach ($seenUuids as $entity_type => $bundles) {
      // We safely sync the following entity types:
      // - nodes/taxonomy/media/block_content/menu_link_content: yes.
      // - file: yes (those referenced by the export).
      // - user: only if not the admin or the current user.
      if (!in_array($entity_type, ['node', 'taxonomy_term', 'media', 'block_content', 'menu_link_content', 'file', 'user'], TRUE)) {
        continue;
      }

      // If there are no files for this type, do not delete anything.
      if (empty($bundles)) {
        continue;
      }

      $storage = $this->entityTypeManager->getStorage($entity_type);

      foreach ($bundles as $bundle => $uuids) {
        $query = $storage->getQuery()->accessCheck(FALSE);

        switch ($entity_type) {
          case 'node':
            $query->condition('type', $bundle);
            break;
          case 'taxonomy_term':
            $query->condition('vid', $bundle);
            break;
          case 'media':
            $query->condition('bundle', $bundle);
            break;
          case 'block_content':
            $query->condition('type', $bundle);
            break;
          case 'menu_link_content':
            $query->condition('menu_name', $bundle);
            break;
        }

        $ids = $query->execute();
        foreach ($storage->loadMultiple($ids) as $entity) {
          $uuid = substr(str_replace('-', '', $entity->uuid()), 0, 8);
          if (isset($uuids[$uuid])) {
            continue;
          }

          // Do not delete the admin user or the currently logged-in user.
          if ($entity_type === 'user') {
            if ($entity->id() === 1 || $entity->id() === $this->currentUser->id()) {
              continue;
            }
          }

          $label = method_exists($entity, 'label') ? $entity->label() : $entity->id();
          $deleted[] = "$entity_type:$bundle: $label ($uuid)";
          $entity->delete();
        }
      }
    }

    return $deleted;
  }


  /**
   * Attempt to reconstruct a full UUID from a short UUID (8 chars).
   *
   * The short UUID is the first 8 characters of the UUID without dashes.
   * The original UUID cannot be recovered exactly, so we generate a new one
   * that starts with those 8 characters to maintain traceability.
   * If the frontmatter contains a full UUID (32 chars without dashes) it is
   * used as-is.
   */
  protected function expandShortUuid(string $short): string {
    $clean = str_replace('-', '', $short);

    // Already a full UUID without dashes (32 chars): format with dashes.
    if (strlen($clean) === 32) {
      return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($clean, 4));
    }

    // Short UUID: pad with zeros and format as a valid UUID.
    $padded = str_pad($clean, 32, '0');
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($padded, 4));
  }


  /**
   * Find a user by account name.
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
   * Recursively find all .md files in a directory.
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

  /**
   * Compare two import files to guarantee a stable sort order.
   *
   * For menu_link_content sorts by menu and weight (parents before children).
   * For all other types sorts by filename.
   */
  protected function compareImportFiles(string $a, string $b): int {
    $metaA = $this->getImportFileMeta($a);
    $metaB = $this->getImportFileMeta($b);

    if ($metaA['type'] === 'menu_link_content' && $metaB['type'] === 'menu_link_content') {
      if ($metaA['menu'] !== $metaB['menu']) {
        return $metaA['menu'] <=> $metaB['menu'];
      }
      return $metaA['weight'] <=> $metaB['weight'];
    }

    return $a <=> $b;
  }

  protected function getImportFileMeta(string $filepath): array {
    $raw = @file_get_contents($filepath);
    if ($raw === FALSE) {
      return ['type' => '', 'menu' => '', 'weight' => 0];
    }

    try {
      $parsed = $this->serializer->deserialize($raw);
    }
    catch (\Exception $e) {
      return ['type' => '', 'menu' => '', 'weight' => 0];
    }

    $frontmatter = $this->serializer->flattenGroups($parsed['frontmatter']);

    return [
      'type'   => $frontmatter['type'] ?? '',
      'menu'   => $frontmatter['menu'] ?? '',
      'weight' => (int) ($frontmatter['weight'] ?? 0),
    ];
  }

}