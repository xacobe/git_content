<?php

namespace Drupal\git_content\Importer;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Normalizer\FieldNormalizer;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\ManagedFields;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\git_content\Utility\EntityLinkRewriteTrait;
use Psr\Log\LoggerInterface;

/**
 * Shared base for all per-entity-type importer classes.
 *
 * Holds the injected services and every helper used by more than one concrete
 * importer: UUID resolution, field denormalization, path aliases, etc.
 * Each subclass must implement import() for its specific entity type.
 */
abstract class BaseImporter {

  use EntityLinkRewriteTrait;
  use StringTranslationTrait;


  protected FieldDiscovery $fieldDiscovery;
  protected MarkdownSerializer $serializer;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected UuidInterface $uuid;
  protected PasswordGeneratorInterface $passwordGenerator;
  protected TimeInterface $time;
  protected LoggerInterface $logger;
  protected AccountProxyInterface $currentUser;

  protected FieldNormalizer $fieldNormalizer;

  public function __construct(
    FieldDiscovery $fieldDiscovery,
    MarkdownSerializer $serializer,
    EntityTypeManagerInterface $entityTypeManager,
    UuidInterface $uuid,
    PasswordGeneratorInterface $passwordGenerator,
    TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
    AccountProxyInterface $currentUser,
    FieldNormalizer $fieldNormalizer,
  ) {
    $this->fieldDiscovery    = $fieldDiscovery;
    $this->serializer        = $serializer;
    $this->entityTypeManager = $entityTypeManager;
    $this->uuid              = $uuid;
    $this->passwordGenerator = $passwordGenerator;
    $this->time              = $time;
    $this->logger            = $loggerFactory->get('git_content');
    $this->currentUser       = $currentUser;
    $this->fieldNormalizer   = $fieldNormalizer;
  }

  /**
   * Import or update a single entity from its parsed frontmatter and body.
   *
   * @param array $frontmatter
   *   Flattened frontmatter data from the .md file.
   * @param string $body
   *   Markdown body content.
   *
   * @return string
   *   'imported' for new entities, 'updated' for existing ones.
   *
   * @throws \Exception
   */
  abstract public function import(array $frontmatter, string $body): string;

  // ---------------------------------------------------------------------------
  // Field population
  // ---------------------------------------------------------------------------

  protected function populateDynamicFields($entity, array $frontmatter, array $definitions, array $extra_skip = []): void {
    $skip = [
      ...ManagedFields::CORE,
      // Frontmatter keys handled explicitly before this loop.
      'bundle', 'vocabulary', 'lang', 'name', 'slug', 'translation_of',
      'weight', 'parent', 'file', 'checksum', 'body_format',
      ...$extra_skip,
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
    return $this->fieldNormalizer->denormalize($value, $definition);
  }

  /**
   * Resolve a frontmatter status value to a Drupal integer (1 or 0).
   *
   * Supports both the new `draft: bool` format (SSG-compatible) and the
   * legacy `status: published|draft` string for backwards compatibility.
   */
  protected function resolveStatus(array $frontmatter): int {
    if (isset($frontmatter['draft'])) {
      return $frontmatter['draft'] ? 0 : 1;
    }
    return ($frontmatter['status'] ?? 'published') === 'published' ? 1 : 0;
  }

  /**
   * Look up an entity by UUID (or create it) and resolve its translation.
   *
   * Encapsulates the repeated lookup → resolveTranslation / create pattern
   * present in every concrete importer. Callers pass the entity type, UUID,
   * langcode, and the values to use when creating a new entity.
   *
   * @param string $entity_type
   *   The entity type machine name (e.g. 'node', 'media').
   * @param string|null $uuid
   *   The UUID from the frontmatter, or NULL if none.
   * @param string $langcode
   *   The langcode from the frontmatter.
   * @param array $create_values
   *   Values passed to storage->create() for new entities. The 'uuid' key is
   *   always set automatically from $uuid (or a new UUID is generated).
   * @param bool $globalLookup
   *   When TRUE, look up by UUID without a bundle filter (e.g. block_content).
   * @param string|null $bundle
   *   Bundle key for the scoped lookup (required when $globalLookup is FALSE).
   *
   * @return array{0: mixed, 1: string}
   *   [entity_or_translation, 'imported'|'updated']
   */
  protected function resolveOrCreate(
    string $entity_type,
    ?string $uuid,
    string $langcode,
    array $create_values,
    bool $globalLookup = FALSE,
    ?string $bundle = NULL,
  ): array {
    $existing = NULL;
    if ($uuid) {
      $existing = $globalLookup
        ? $this->findByUuidGlobal($uuid, $entity_type)
        : $this->findByUuid($uuid, $entity_type, $bundle ?? '');
    }

    if ($existing) {
      return $this->resolveTranslation($existing, $langcode);
    }

    $entity = $this->entityTypeManager->getStorage($entity_type)->create(
      $create_values + ['uuid' => $uuid ?? $this->uuid->generate()]
    );
    return [$entity, 'imported'];
  }

  /**
   * Resolve the correct translation entity and operation string.
   *
   * Extracts the repeated translation-handling block present in every
   * concrete importer: if the translation already exists, get it ('updated');
   * otherwise add it ('imported').
   *
   * @return array{0: mixed, 1: string}
   *   [entity_translation, 'updated'|'imported']
   */
  protected function resolveTranslation($existing, string $langcode): array {
    if ($existing->hasTranslation($langcode)) {
      return [$existing->getTranslation($langcode), 'updated'];
    }
    return [$existing->addTranslation($langcode), 'imported'];
  }

  /**
   * Set the body field from Markdown, if the entity has one.
   */
  protected function setBody($entity, string $body, string $format = 'basic_html'): void {
    if ($entity->hasField('body') && !empty($body)) {
      $html = $format === 'full_html' ? $body : $this->serializer->markdownToHtml($body);
      $entity->set('body', [
        'value'  => $this->rewriteEntityUuidsToIds($html),
        'format' => $format,
      ]);
    }
  }

  // ---------------------------------------------------------------------------
  // Reference resolution
  // ---------------------------------------------------------------------------

  protected function findByUuid(string $uuid, string $entity_type, string $bundle): mixed {
    $bundle_key = match($entity_type) {
      'node'              => 'type',
      'taxonomy_term'     => 'vid',
      'media'             => 'bundle',
      'block_content'     => 'type',
      'menu_link_content' => 'menu_name',
      default             => 'type',
    };

    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($bundle_key, $bundle)
      ->condition('uuid', $uuid)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }
    return $storage->load(reset($ids));
  }

  /**
   * Find an entity by UUID without filtering by bundle.
   * Safer when the bundle may have changed or is not reliable.
   */
  protected function findByUuidGlobal(string $uuid, string $entity_type): mixed {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }
    return $storage->load(reset($ids));
  }

  protected function setAuthor($entity, array $frontmatter): void {
    if (!empty($frontmatter['author']) && $entity->hasField('uid')) {
      $uid = $this->findUserByName($frontmatter['author']);
      if ($uid) {
        $entity->set('uid', $uid);
      }
    }
  }

  protected function findUserByName(string $name): ?int {
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['name' => $name]);
    return !empty($users) ? (int) reset($users)->id() : NULL;
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

}


