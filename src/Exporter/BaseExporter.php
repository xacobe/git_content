<?php

namespace Drupal\git_content\Exporter;

use Drupal\git_content\Discovery\FieldDiscovery;
use Drupal\git_content\Normalizer\FieldNormalizer;
use Drupal\git_content\Serializer\MarkdownSerializer;
use Drupal\git_content\Utility\ChecksumTrait;
use Drupal\git_content\Utility\ContentExportTrait;
use Drupal\git_content\Utility\EntityLinkRewriteTrait;
use Drupal\git_content\Utility\ManagedFields;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class for all entity exporters.
 *
 * Contains shared logic for normalizing fields and building frontmatter.
 * Each concrete exporter (NodeExporter, TaxonomyExporter, etc.) extends
 * this class and implements entity-specific behavior.
 *
 * All entity queries use accessCheck(FALSE). Export is a privileged batch
 * operation; skipping access checks is intentional so unpublished content
 * is included in the export.
 */
abstract class BaseExporter implements ExporterInterface {

  use ChecksumTrait;
  use ContentExportTrait;
  use EntityLinkRewriteTrait;

  protected FieldDiscovery $fieldDiscovery;
  protected MarkdownSerializer $serializer;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected LoggerInterface $logger;

  /**
   * Base fields handled manually; excluded from the dynamic field loop.
   *
   * Extends ManagedFields::CORE with exporter-specific fields that are
   * serialized individually (body, uid) or intentionally omitted.
   */
  /**
   * Fields excluded from the managed list for this specific exporter.
   * Subclasses can whitelist fields that are in $managedFields (or CORE)
   * but should flow through the dynamic loop for this entity type.
   */
  protected array $allowedFields = [];

  protected array $managedFields = [
    ...ManagedFields::CORE,
    // Exported individually by each exporter, not via the dynamic loop.
    'body', 'uid',
    // Revision owner — not meaningful for static content.
    'revision_uid',
    // SEO metadata — only meaningful for nodes; excluded everywhere else.
    'metatag',
  ];

  protected FieldNormalizer $fieldNormalizer;

  public function __construct(
    FieldDiscovery $fieldDiscovery,
    MarkdownSerializer $serializer,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    FieldNormalizer $fieldNormalizer,
  ) {
    $this->fieldDiscovery    = $fieldDiscovery;
    $this->serializer        = $serializer;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger            = $loggerFactory->get('git_content');
    $this->fieldNormalizer   = $fieldNormalizer;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function getEntityType(): string;

  /**
   * The subdirectory within content_export/ for this entity type.
   *
   * e.g. 'content_types', 'taxonomy', 'media', 'blocks', 'files', 'users', 'menus'.
   *
   * Phase 2 migration: this will become non-abstract in BaseExporter, reading
   * from config (git_content.settings) and falling back to defaultTypeDir()
   * (rename of this method). No changes needed in subclasses beyond the rename.
   */
  abstract protected function typeDir(): string;

  /**
   * Export the entity to a Markdown file on disk.
   *
   * @return array{path: string, skipped: bool}
   *   Generated file information.
   */
  abstract public function exportToFile(EntityInterface $entity, bool $dryRun = FALSE): array;

  /**
   * Generate the full Markdown contents for the entity.
   *
   * @return string
   *   The .md file contents.
   */
  abstract public function export(EntityInterface $entity): string;

  // ---------------------------------------------------------------------------
  // Shared helpers
  // ---------------------------------------------------------------------------

  /**
   * Build the block of dynamic fields grouped by type
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
      if (in_array($field_name, $this->managedFields) && !in_array($field_name, $this->allowedFields)) {
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
   * Normalize a field value into a clean format for frontmatter.
   */
  protected function normalizeField($field, FieldDefinitionInterface $definition): mixed {
    return $this->fieldNormalizer->normalize($field, $definition);
  }

  /**
   * Get a slug based on the entity's path alias.
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
   * Get the full path alias for the entity.
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
   * Separate Drupal-internal fields from SSG-visible fields and add checksum.
   *
   * Drupal-internal fields are placed after a '# Drupal' YAML comment separator.
   * YAML parsers ignore the comment; all fields remain flat and directly accessible.
   * The checksum covers all entity data (SSG-visible + Drupal-internal).
   */
  protected function wrapDrupalNamespace(array $frontmatter, string $body, array $extra = []): array {
    $drupal_keys = ['uuid', 'translation_of', 'body_format', 'tid', ...$extra];

    // Split frontmatter into SSG fields and Drupal-internal fields.
    $ssg    = [];
    $drupal = [];
    foreach ($frontmatter as $key => $value) {
      if (in_array($key, $drupal_keys, TRUE)) {
        $drupal[$key] = $value;
      }
      else {
        $ssg[$key] = $value;
      }
    }

    // Checksum covers all entity data: SSG fields + Drupal-internal fields.
    $all_flat = array_merge($this->serializer->flattenGroups($ssg), $drupal);
    $checksum = $this->computeChecksum($all_flat, $body);

    // Rebuild: SSG fields first, then a visual '# Drupal' separator,
    // then Drupal-internal fields, then checksum — all at the same level.
    $result = $ssg;
    if (!empty($drupal)) {
      $result['# Drupal'] = NULL; // comment header (rendered by buildCleanYaml)
      foreach ($drupal as $key => $value) {
        $result[$key] = $value;
      }
    }
    $result['checksum'] = $checksum;

    return $result;
  }

  /**
   * Return the account name of the entity owner, or NULL if none.
   */
  protected function getAuthorName(EntityInterface $entity): ?string {
    if (!$entity->hasField('uid')) {
      return NULL;
    }
    $owner = $entity->get('uid')->entity;
    return $owner ? $owner->getAccountName() : NULL;
  }

  /**
   * Return the UUID of the original entity when this is a translation.
   */
  protected function getTranslationOf(EntityInterface $entity): ?string {
    if (!$entity->isDefaultTranslation()) {
      return $entity->getUntranslated()->uuid();
    }
    return NULL;
  }

  /**
   * Write a file only if its contents have changed.
   *
   * In dry-run mode the comparison is checksum-based rather than raw-content
   * based.  This prevents false positives when a .md file was manually edited:
   * the entity has not changed (export concern), only the file has (import
   * concern), so the checksums still match and dry-run correctly returns FALSE.
   *
   * @return bool
   *   TRUE if the file was written (or would be written), FALSE if skipped.
   */
  protected function writeIfChanged(string $filepath, string $content, bool $dryRun = FALSE): bool {
    if (!file_exists($filepath)) {
      if (!$dryRun) {
        file_put_contents($filepath, $content);
      }
      return TRUE;
    }

    if ($dryRun) {
      // Compare only the checksum field. The checksum encodes the entity
      // state at last export; if it matches the entity's current output the
      // entity has not changed, even if the file was manually edited.
      $existing         = $this->serializer->deserialize(file_get_contents($filepath));
      $existingChecksum = $existing['frontmatter']['checksum'] ?? NULL;
      preg_match('/^checksum:\s*([0-9a-f]+)\s*$/m', $content, $m);
      $generatedChecksum = $m[1] ?? NULL;
      return $existingChecksum !== $generatedChecksum;
    }

    if (file_get_contents($filepath) === $content) {
      return FALSE;
    }
    file_put_contents($filepath, $content);
    return TRUE;
  }

  /**
   * Create the export directory if it does not exist.
   */
  protected function ensureDir(string $dir, bool $dryRun = FALSE): void {
    if (!$dryRun && !file_exists($dir)) {
      mkdir($dir, 0775, TRUE);
    }
  }

  /**
   * Export the body field respecting its text format.
   *
   * - full_html  → pretty-prints with tidy (if available) and adds
   *                `body_format: full_html` to frontmatter so the importer
   *                can round-trip the HTML without converting it to Markdown.
   * - other      → converts HTML to Markdown (default behaviour).
   *
   * @param array $frontmatter Passed by reference; body_format is added when needed.
   * @return string The body content ready to embed in the .md file.
   */
  protected function exportBodyField(EntityInterface $entity, array &$frontmatter): string {
    if (!$entity->hasField('body') || $entity->get('body')->isEmpty()) {
      return '';
    }
    $body_field  = $entity->get('body');
    $body_format = $body_field->format ?? 'basic_html';
    $raw = $this->rewriteEntityIdsToUuids($body_field->value ?? '');
    if ($body_format === 'full_html') {
      $frontmatter['body_format'] = 'full_html';
      return $this->serializer->prettyHtml($raw) ?? '';
    }
    return $this->serializer->htmlToMarkdown($raw);
  }

  /**
   * Strip Drupal stream wrapper prefix from a URI, returning a plain path.
   *
   * Examples:
   *   public://images/photo.jpg  → images/photo.jpg
   *   private://docs/report.pdf  → docs/report.pdf
   */
  protected function stripStreamWrapper(string $uri): string {
    return preg_replace('/^[a-z][a-z0-9+\-.]*:\/\//', '', $uri);
  }

  /**
   * Convert a string to a URL-safe slug.
   */
  protected function slugify(string $text): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($text)), '-');
  }

  /**
   * Build the .md filename including the language suffix.
   *
   * Examples: my-article.es.md, contact.en.md
   * Language-neutral entities (und) omit the suffix: my-file.md
   */
  protected function buildFilename(string $slug, string $langcode): string {
    return $langcode !== 'und' ? $slug . '.' . $langcode . '.md' : $slug . '.md';
  }

  /**
   * Pluralise a bundle machine name for use as a directory.
   *
   * article → articles, page → pages, category → categories.
   */
  protected function pluralBundle(string $bundle): string {
    if (str_ends_with($bundle, 'y')) {
      return substr($bundle, 0, -1) . 'ies';
    }
    return $bundle . 's';
  }

  /**
   * Write all dynamic field groups (taxonomy, media, references, extra) into
   * the frontmatter array. Handles all entity types uniformly.
   *
   * Replaces the repeated if/foreach group blocks in each concrete exporter.
   */
  protected function applyDynamicGroups(array &$frontmatter, EntityInterface $entity, string $entity_type): void {
    $groups = $this->buildDynamicGroups($entity, $entity_type);

    if (!empty($groups['taxonomy'])) {
      $frontmatter['taxonomy'] = $groups['taxonomy'];
    }
    if (!empty($groups['media'])) {
      $frontmatter['media'] = $groups['media'];
    }
    if (!empty($groups['references'])) {
      $frontmatter['references'] = $groups['references'];
    }
    foreach ($groups['extra'] as $key => $val) {
      $frontmatter[$key] = $val;
    }
  }

}