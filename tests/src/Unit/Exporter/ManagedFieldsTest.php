<?php

namespace Drupal\Tests\git_content\Unit\Exporter;

use Drupal\git_content\Exporter\BaseExporter;
use Drupal\git_content\Exporter\BlockContentExporter;
use Drupal\git_content\Exporter\MediaExporter;
use Drupal\git_content\Exporter\NodeExporter;
use Drupal\git_content\Exporter\UserExporter;
use Drupal\git_content\Utility\ManagedFields;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the managed/allowed fields configuration across exporters.
 *
 * The key invariant: metatag must NOT be in ManagedFields::CORE (so
 * FieldDiscovery can return it), but MUST be excluded by default via
 * BaseExporter::$managedFields, and NodeExporter must override this
 * via $allowedFields so it passes through for nodes only.
 *
 * @group git_content
 */
class ManagedFieldsTest extends UnitTestCase {

  /**
   * metatag must NOT be in CORE because FieldDiscovery::getFields() uses CORE
   * to filter fields before they even reach the exporter. If it were in CORE,
   * it would never reach NodeExporter's allowedFields check.
   */
  public function testMetatagNotInCore(): void {
    $this->assertNotContains(
      'metatag',
      ManagedFields::CORE,
      'metatag must not be in CORE — FieldDiscovery would drop it before the exporter sees it.'
    );
  }

  /**
   * BaseExporter must list metatag as managed (excluded) so all entity types
   * that do not override $managedFields skip it by default.
   */
  public function testMetatagInBaseExporterManagedFields(): void {
    $defaults = (new \ReflectionClass(BaseExporter::class))->getDefaultProperties();
    $this->assertContains(
      'metatag',
      $defaults['managedFields'],
      'BaseExporter::$managedFields must contain metatag to exclude it by default.'
    );
  }

  /**
   * NodeExporter must allow metatag through via $allowedFields.
   */
  public function testNodeExporterAllowsMetatag(): void {
    $defaults = (new \ReflectionClass(NodeExporter::class))->getDefaultProperties();
    $this->assertContains(
      'metatag',
      $defaults['allowedFields'],
      'NodeExporter::$allowedFields must contain metatag so SEO data is exported for nodes.'
    );
  }

  /**
   * Exporters that override $managedFields must still include metatag
   * since they replace the parent property entirely.
   */
  public function testUserExporterExcludesMetatag(): void {
    $defaults = (new \ReflectionClass(UserExporter::class))->getDefaultProperties();
    $this->assertContains('metatag', $defaults['managedFields']);
  }

  public function testMediaExporterExcludesMetatag(): void {
    $defaults = (new \ReflectionClass(MediaExporter::class))->getDefaultProperties();
    $this->assertContains('metatag', $defaults['managedFields']);
  }

  /**
   * MediaExporter must exclude source file fields since getSourceFile() already
   * captures them as the top-level 'file:' key, avoiding redundant media: group.
   */
  public function testMediaExporterExcludesSourceFileFields(): void {
    $defaults = (new \ReflectionClass(MediaExporter::class))->getDefaultProperties();
    $sourceFields = [
      'field_media_image',
      'field_media_file',
      'field_media_video_file',
      'field_media_audio_file',
      'thumbnail',
    ];
    foreach ($sourceFields as $field) {
      $this->assertContains($field, $defaults['managedFields'], "$field must be in MediaExporter::\$managedFields");
    }
  }

  /**
   * UserExporter must exclude user_picture (exported as 'avatar:') and
   * preferred_langcode (set from lang: on import).
   */
  public function testUserExporterExcludesUserPictureAndPreferredLangcode(): void {
    $defaults = (new \ReflectionClass(UserExporter::class))->getDefaultProperties();
    $this->assertContains('user_picture', $defaults['managedFields']);
    $this->assertContains('preferred_langcode', $defaults['managedFields']);
  }

  /**
   * Exporters that inherit $managedFields from BaseExporter (without overriding)
   * also benefit from the base metatag exclusion.
   */
  public function testBlockContentExporterInheritsBaseExclusion(): void {
    $reflection = new \ReflectionClass(BlockContentExporter::class);
    // BlockContentExporter does not declare its own $managedFields,
    // so getDefaultProperties() returns the inherited base value.
    $declaring = $reflection->getProperty('managedFields')->getDeclaringClass();
    $this->assertEquals(BaseExporter::class, $declaring->getName());
  }

}
