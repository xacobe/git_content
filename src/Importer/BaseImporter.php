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
use Psr\Log\LoggerInterface;

/**
 * Shared base for all per-entity-type importer classes.
 *
 * Holds the injected services and every helper used by more than one concrete
 * importer: UUID resolution, field denormalization, path aliases, etc.
 * Each subclass must implement import() for its specific entity type.
 */
abstract class BaseImporter {

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
    return $this->fieldNormalizer->denormalize($value, $definition);
  }

  // ---------------------------------------------------------------------------
  // Reference resolution
  // ---------------------------------------------------------------------------

  protected function findByShortUuid(string $short_uuid, string $entity_type, string $bundle): mixed {
    $bundle_key = match($entity_type) {
      'node'              => 'type',
      'taxonomy_term'     => 'vid',
      'media'             => 'bundle',
      'block_content'     => 'type',
      'menu_link_content' => 'menu_name',
      default             => 'type',
    };

    // The short UUID is the first 8 chars of the full UUID (before the first
    // dash), so a LIKE prefix query lets the DB use its UUID index instead of
    // loading every entity into PHP.
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($bundle_key, $bundle)
      ->condition('uuid', $short_uuid . '%', 'LIKE')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }
    return $storage->load(reset($ids));
  }

  /**
   * Find an entity by short UUID without filtering by bundle.
   * Safer when the bundle may have changed or is not reliable.
   */
  protected function findByShortUuidGlobal(string $short_uuid, string $entity_type): mixed {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $short_uuid . '%', 'LIKE')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }
    return $storage->load(reset($ids));
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

  /**
   * Attempt to reconstruct a full UUID from a short UUID (8 chars).
   *
   * The short UUID is the first 8 characters of the UUID without dashes.
   * If the frontmatter contains a full UUID (32 chars without dashes) it is
   * used as-is; otherwise a new UUID is generated that starts with those 8
   * characters to maintain traceability.
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

}

