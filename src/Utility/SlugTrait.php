<?php

namespace Drupal\git_content\Utility;

use Drupal\Core\Entity\EntityInterface;

/**
 * Slug generation utilities for entities and arbitrary strings.
 */
trait SlugTrait {

  /**
   * Derive a URL-safe slug from an entity's path alias.
   *
   * Falls back to entity-type-ID when no alias is available.
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
   * Convert a string to a URL-safe slug.
   */
  protected function slugify(string $text): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($text)), '-');
  }

}
