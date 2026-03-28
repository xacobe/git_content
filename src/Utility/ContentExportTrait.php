<?php

namespace Drupal\git_content\Utility;

use Drupal\Component\Utility\Settings;

/**
 * Provides shared file-system helpers for the content_export/ directory.
 *
 * Used by both MarkdownExporter and MarkdownImporter so the base path and
 * the recursive .md scanner are defined in exactly one place.
 *
 * The export directory defaults to content_export/ in the Drupal root.
 * Override per environment in settings.php:
 *   $settings['git_content_export_dir'] = '../my-custom-export';
 * Relative paths are resolved from DRUPAL_ROOT.
 */
trait ContentExportTrait {

  /**
   * Absolute path to the content_export directory.
   */
  protected function contentExportDir(): string {
    $configured = Settings::get('git_content_export_dir');
    if ($configured) {
      return str_starts_with($configured, '/') ? $configured : DRUPAL_ROOT . '/' . $configured;
    }
    return DRUPAL_ROOT . '/content_export';
  }

  /**
   * Recursively find all .md files under content_export/.
   *
   * Returns absolute paths. Returns an empty array when the directory does
   * not exist (callers can decide whether that is an error condition).
   *
   * @return string[]
   */
  protected function scanMarkdownFiles(): array {
    $dir = $this->contentExportDir();
    if (!is_dir($dir)) {
      return [];
    }

    $files = [];
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
      if ($file->isFile() && $file->getExtension() === 'md') {
        $files[] = $file->getRealPath();
      }
    }
    return $files;
  }

}
