<?php

namespace Drupal\git_content\Utility;

/**
 * Drupal system fields that are never processed by the dynamic field loop.
 *
 * Both BaseExporter and MarkdownImporter maintain a skip-list of fields they
 * handle explicitly (or intentionally ignore). The entries below are the common
 * core — fields present on virtually every entity type. Each class may extend
 * the list with entity-type-specific extras.
 */
final class ManagedFields {

  /**
   * Core Drupal system fields excluded from generic field processing.
   *
   * @var string[]
   */
  public const CORE = [
    // Entity identity
    'nid', 'vid', 'tid', 'mid', 'id', 'uuid', 'type', 'langcode',
    // Common metadata
    'status', 'created', 'changed', 'title', 'path',
    // Revision bookkeeping
    'revision_timestamp', 'revision_uid', 'revision_log', 'revision_log_message',
    'revision_default', 'revision_translation_affected', 'revision_id',
    'revision_created', 'revision_user',
    // Translation
    'default_langcode', 'content_translation_source', 'content_translation_outdated',
    // User session / security — never exported or imported
    'pass', 'access', 'login', 'init',
    // Comment fields — not used in static content workflows
    'comment', 'comment_count', 'comment_status', 'last_comment_timestamp',
    'last_comment_name', 'last_comment_uid',
    // block_content: info is handled as 'title' by BlockContentExporter
    'info',
  ];

}
