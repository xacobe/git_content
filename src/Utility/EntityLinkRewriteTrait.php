<?php

namespace Drupal\git_content\Utility;

/**
 * Rewrites entity:TYPE/NID links ↔ entity:TYPE/UUID for portability.
 *
 * Drupal's CKEditor and link fields store internal references as
 * entity:node/42, entity:media/7, etc. These numeric IDs are
 * environment-specific — a node imported from another environment will
 * get a different ID. This trait converts them to UUID-based links on
 * export and restores the correct local ID on import, mirroring the
 * pattern already used for menu links (getPortableUri / MenuLinkImporter).
 *
 * Classes using this trait must have $this->entityTypeManager available.
 */
trait EntityLinkRewriteTrait {

  private const REWRITABLE_ENTITY_TYPES = [
    'node', 'media', 'taxonomy_term', 'block_content', 'file',
  ];

  /**
   * Replace entity:TYPE/NID with entity:TYPE/UUID in an HTML string.
   *
   * Safe to call on arbitrary HTML: unresolvable IDs are left unchanged.
   */
  protected function rewriteEntityIdsToUuids(string $html): string {
    foreach (self::REWRITABLE_ENTITY_TYPES as $type) {
      $html = preg_replace_callback(
        '/\bentity:' . preg_quote($type, '/') . '\/(\d+)\b/',
        function (array $m) use ($type): string {
          $entity = $this->entityTypeManager->getStorage($type)->load((int) $m[1]);
          return $entity ? 'entity:' . $type . '/' . $entity->uuid() : $m[0];
        },
        $html,
      );
    }
    return $html;
  }

  /**
   * Replace entity:TYPE/UUID with entity:TYPE/NID in an HTML string.
   *
   * Safe to call when the referenced entity does not yet exist: the UUID
   * form is kept as-is so a second import attempt (or the two-pass pattern)
   * can still resolve it later.
   */
  protected function rewriteEntityUuidsToIds(string $html): string {
    $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    foreach (self::REWRITABLE_ENTITY_TYPES as $type) {
      $html = preg_replace_callback(
        '/\bentity:' . preg_quote($type, '/') . '\/(' . $uuid . ')\b/i',
        function (array $m) use ($type): string {
          $entities = $this->entityTypeManager
            ->getStorage($type)
            ->loadByProperties(['uuid' => $m[1]]);
          return !empty($entities)
            ? 'entity:' . $type . '/' . reset($entities)->id()
            : $m[0];
        },
        $html,
      );
    }
    return $html;
  }

}
