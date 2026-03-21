<?php

namespace Drupal\git_content\Utility;

use Drupal\Core\Entity\EntityInterface;

/**
 * Derives a URL-safe slug from an entity's path alias.
 *
 * Falls back to entity-type-ID when no alias is available.
 */
trait SlugTrait {

  protected function getSlug(EntityInterface $entity): string {
    if ($entity->hasField('path') && !$entity->get('path')->isEmpty()) {
      $alias = $entity->get('path')->alias;
      if ($alias) {
        return ltrim(basename($alias), '/');
      }
    }
    return $entity->getEntityTypeId() . '-' . $entity->id();
  }

}
