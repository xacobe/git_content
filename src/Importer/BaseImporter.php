<?php

namespace Drupal\git_content\Importer;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Normalizer\FieldNormalizer;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\DateParseTrait;
use Drupal\git_content\Utility\ManagedFields;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;

/**
 * Shared base for all per-entity-type importer classes.
 *
 * Holds the injected services and every helper used by more than one concrete
 * importer: entity ID resolution, field denormalization, path aliases, etc.
 * Each subclass must implement import() for its specific entity type.
 *
 * All entity queries in this class use accessCheck(FALSE). Import and export
 * are privileged batch operations that run as an admin user; skipping access
 * checks is intentional and avoids false negatives on unpublished content.
 */
abstract class BaseImporter implements ImporterInterface {

  use DateParseTrait;
  use StringTranslationTrait;


  protected LoggerInterface $logger;

  public function __construct(
    protected FieldDiscovery $fieldDiscovery,
    protected MarkdownSerializer $serializer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PasswordGeneratorInterface $passwordGenerator,
    protected TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
    protected AccountProxyInterface $currentUser,
    protected FieldNormalizer $fieldNormalizer,
  ) {
    $this->logger = $loggerFactory->get('git_content');
  }

  /**
   * {@inheritdoc}
   */
  abstract public function getEntityType(): ?string;

  /**
   * {@inheritdoc}
   */
  abstract public function getImportWeight(): int;

  /**
   * {@inheritdoc}
   */
  abstract public function extractEntityId(array $frontmatter): ?int;

  /**
   * {@inheritdoc}
   */
  abstract public function resolveBundle(array $frontmatter): ?string;

  /**
   * {@inheritdoc}
   */
  abstract public function getBundleQueryField(): ?string;

  /**
   * {@inheritdoc}
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
   * Resolve the frontmatter `draft` flag to a Drupal status integer (1 or 0).
   */
  protected function resolveStatus(array $frontmatter): int {
    return ($frontmatter['draft'] ?? FALSE) ? 0 : 1;
  }

  /**
   * Inject the entity's original ID into $create_values when the slot is free.
   *
   * Called before resolveOrCreate() to preserve entity IDs across DB resets so
   * references stored by ID (views filters, site config, etc.) keep working.
   *
   * @param string $entity_type  The entity type machine name.
   * @param string $entity_field The entity storage ID field (e.g. 'nid', 'mid', 'id').
   * @param string $fm_key       The frontmatter key holding the ID (e.g. 'nid', 'block_id').
   * @param array  $create_values Passed by reference; ID is added when the slot is free.
   * @param array  $frontmatter  Parsed frontmatter from the .md file.
   * @param int    $min_id       Minimum acceptable ID (default 1); use 2 for users to skip uid=1.
   */
  protected function preserveEntityId(
    string $entity_type,
    string $entity_field,
    string $fm_key,
    array &$create_values,
    array $frontmatter,
    int $min_id = 1,
  ): void {
    if (empty($frontmatter[$fm_key])) {
      return;
    }
    $requested_id = (int) $frontmatter[$fm_key];
    if ($requested_id < $min_id) {
      return;
    }
    if (!$this->entityTypeManager->getStorage($entity_type)->load($requested_id)) {
      $create_values[$entity_field] = $requested_id;
    }
  }

  /**
   * Look up an entity by ID (or create it) and resolve its translation.
   *
   * Encapsulates the repeated lookup → resolveTranslation / create pattern
   * present in every concrete importer. Callers pass the entity type, primary
   * ID, langcode, and the values to use when creating a new entity.
   *
   * @param string $entity_type
   *   The entity type machine name (e.g. 'node', 'media').
   * @param int|null $entity_id
   *   The entity ID from the frontmatter (nid, tid, mid, etc.), or NULL.
   * @param string $langcode
   *   The langcode from the frontmatter.
   * @param array $create_values
   *   Values passed to storage->create() for new entities.
   *
   * @return array{0: mixed, 1: string}
   *   [entity_or_translation, 'imported'|'updated']
   */
  protected function resolveOrCreate(
    string $entity_type,
    ?int $entity_id,
    string $langcode,
    array $create_values,
  ): array {
    $existing = $entity_id
      ? $this->entityTypeManager->getStorage($entity_type)->load($entity_id)
      : NULL;

    if ($existing) {
      return $this->resolveTranslation($existing, $langcode);
    }

    $entity = $this->entityTypeManager->getStorage($entity_type)->create($create_values);
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
        'value'  => $html,
        'format' => $format,
      ]);
    }
  }

  // ---------------------------------------------------------------------------
  // Reference resolution
  // ---------------------------------------------------------------------------

  protected function setAuthor($entity, array $frontmatter): void {
    if (!empty($frontmatter['author']) && $entity->hasField('uid')) {
      $uid = $this->findUserByName($frontmatter['author']);
      if ($uid) {
        $entity->set('uid', $uid);
      }
    }
  }

  protected function findUserByName(string $name): ?int {
    $user = $this->loadOneByProperty('user', 'name', $name);
    return $user ? (int) $user->id() : NULL;
  }

  /**
   * Load a single entity by a property value, or NULL if not found.
   */
  protected function loadOneByProperty(string $entity_type, string $property, mixed $value): mixed {
    $results = $this->entityTypeManager->getStorage($entity_type)
      ->loadByProperties([$property => $value]);
    return !empty($results) ? reset($results) : NULL;
  }


}



