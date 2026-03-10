<?php

namespace Drupal\git_content\Utility;

/**
 * Provides shared file-system helpers for the content_export/ directory.
 *
 * Used by both MarkdownExporter and MarkdownImporter so the base path and
 * the recursive .md scanner are defined in exactly one place.
 */
trait ContentExportTrait {

  /**
   * Absolute path to the content_export directory.
   */
  protected function contentExportDir(): string {
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
