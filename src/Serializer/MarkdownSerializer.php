<?php

namespace Drupal\git_content\Serializer;

use Symfony\Component\Yaml\Yaml;

/**
 * Serialize and deserialize Markdown files with YAML frontmatter.
 *
 * This class is the single source of truth for the on-disk file format:
 * how human-readable YAML is built and how it is parsed back.
 * Exporters and importers delegate formatting logic to this class.
 */
class MarkdownSerializer {

  private ?\League\HTMLToMarkdown\HtmlConverter $htmlConverter = NULL;
  private ?\League\CommonMark\CommonMarkConverter $markdownConverter = NULL;

  /**
   * Build a full Markdown file from frontmatter and body.
   *
   * @param array $frontmatter
   *   Structured data. Keys that are only underscores ('_', '__', …) are
   *   rendered as blank lines for readability.
   * @param string $body
   *   Body content in Markdown (may be empty).
   *
   * @return string
   *   The full .md file contents.
   */
  public function serialize(array $frontmatter, string $body = ''): string {
    $yaml = $this->buildCleanYaml($frontmatter);
    return "---\n" . $yaml . "---\n\n" . $body;
  }

  /**
   * Parse a Markdown file with YAML frontmatter.
   *
   * @param string $raw
   *   Raw contents of the .md file.
   *
   * @return array{frontmatter: array, body: string}
   *   Array with keys 'frontmatter' (array) and 'body' (string).
   *
   * @throws \InvalidArgumentException
   */
  public function deserialize(string $raw): array {
    // Normalize Windows line endings so the regex works on files from any OS.
    $raw = str_replace("\r\n", "\n", $raw);
    if (!str_starts_with(ltrim($raw), '---')) {
      throw new \InvalidArgumentException('File does not contain valid YAML frontmatter (missing --- delimiter).');
    }

    $pattern = '/^---\s*\n(.*?)\n---\s*\n?(.*)/s';
    if (!preg_match($pattern, ltrim($raw), $matches)) {
      throw new \InvalidArgumentException('Failed to parse YAML frontmatter.');
    }

    $yaml_raw = $matches[1];
    $body = trim($matches[2]);

    $frontmatter = Yaml::parse($yaml_raw) ?? [];

    return [
      'frontmatter' => $frontmatter,
      'body'        => $body,
    ];
  }

  /**
   * Flatten taxonomy, media and references groups to the root level.
   * Useful for the importer, which operates on a per-field basis.
   *
   * @param array $frontmatter
   *   Frontmatter with possible nested groups.
   *
   * @return array
   *   Frontmatter with groups flattened.
   */
  public function flattenGroups(array $frontmatter): array {
    foreach (['taxonomy', 'media', 'references'] as $group) {
      if (isset($frontmatter[$group]) && is_array($frontmatter[$group])) {
        foreach ($frontmatter[$group] as $key => $value) {
          if (!isset($frontmatter[$key])) {
            $frontmatter[$key] = $value;
          }
        }
        unset($frontmatter[$group]);
      }
    }
    return $frontmatter;
  }

  /**
   * Pretty-print HTML using the tidy extension if available.
   *
   * Falls back to the raw string when tidy is not loaded, so the module
   * works in any environment regardless of whether the extension is installed.
   * Skips tidy entirely when the content has no HTML tags (e.g. plain text
   * stored in a full_html field) to avoid mangling Markdown autolinks.
   */
  public function prettyHtml(?string $html): ?string {
    if ($html === NULL) {
      return NULL;
    }
    // Only run tidy when the content contains real HTML tags.
    if (!preg_match('/<[a-z][a-z0-9-]*(?:\s[^>]*)?\/?>/', $html) || !extension_loaded('tidy')) {
      return $html;
    }
    $tidy = new \tidy();
    $tidy->parseString($html, [
      'indent'         => TRUE,
      'indent-spaces'  => 2,
      'wrap'           => 0,
      'output-html'    => TRUE,
      'show-body-only' => TRUE,
    ], 'utf8');
    $tidy->cleanRepair();
    return trim((string) $tidy);
  }

  /**
   * Convert HTML to Markdown using league/html-to-markdown.
   */
  public function htmlToMarkdown(string $html): string {
    $this->htmlConverter ??= new \League\HTMLToMarkdown\HtmlConverter([
      'strip_tags'   => FALSE,
      'bold_style'   => '**',
      'italic_style' => '_',
    ]);
    return $this->htmlConverter->convert($html);
  }

  /**
   * Convert Markdown to HTML using league/commonmark.
   */
  public function markdownToHtml(string $markdown): string {
    $this->markdownConverter ??= new \League\CommonMark\CommonMarkConverter();
    return (string) $this->markdownConverter->convert($markdown);
  }

  // ---------------------------------------------------------------------------
  // Private
  // ---------------------------------------------------------------------------

  /**
   * Build clean YAML with support for blank lines via placeholder keys.
   */
  private function buildCleanYaml(array $frontmatter): string {
    $lines = [];

    foreach ($frontmatter as $key => $value) {
      // Comment-marker keys (starting with #) → YAML comment line.
      // Used to visually separate Drupal-internal fields from SSG fields.
      if (str_starts_with((string) $key, '#')) {
        $lines[] = (string) $key;
        continue;
      }

      if ($value === NULL) {
        $lines[] = $key . ': null';
        continue;
      }

      if (is_bool($value)) {
        $lines[] = $key . ': ' . ($value ? 'true' : 'false');
        continue;
      }

      if (is_array($value)) {
        $lines[] = rtrim(Yaml::dump([$key => $value], 10, 2, Yaml::DUMP_NULL_AS_TILDE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        continue;
      }

      $lines[] = rtrim(Yaml::dump([$key => $value], 1, 2));
    }

    return implode("\n", $lines) . "\n";
  }

}
