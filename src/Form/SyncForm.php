<?php

namespace Drupal\git_content\Form;

use Drupal\git_content\Exporter\MarkdownExporter;
use Drupal\git_content\Importer\MarkdownImporter;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unified dashboard for Git Content sync status, export, and import.
 *
 * Shows the current synchronisation state between Drupal and content_export/,
 * following the same UX pattern as Drupal core's Configuration Synchronization
 * page (admin/config/development/configuration).
 *
 * The import panel runs a dry-run preview on every page load so the user
 * always sees the current state. Submitting either button runs the real
 * operation and redirects back so the status refreshes automatically.
 */
class SyncForm extends FormBase {

  public function __construct(
    protected MarkdownExporter $exporter,
    protected MarkdownImporter $importer,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('git_content.exporter'),
      $container->get('git_content.importer'),
    );
  }

  public function getFormId(): string {
    return 'git_content_sync_form';
  }

  // ---------------------------------------------------------------------------
  // Form builder
  // ---------------------------------------------------------------------------

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $exportPreview = $this->exporter->previewAll();
    $importPreview = $this->importer->previewAll();

    $pendingExport = count($exportPreview['exported']) + count($exportPreview['deleted']);
    $pendingImport = count($importPreview['imported'])
      + count($importPreview['updated'])
      + count($importPreview['deleted']);

    $form['#attributes']['class'][] = 'layout-container';

    $form['summary'] = $this->buildSummary($pendingExport, $pendingImport);

    if (!empty($exportPreview['warnings'])) {
      $form['export_warnings'] = $this->buildWarningsBox($exportPreview['warnings']);
    }

    // --- Export panel ---
    $form['export_details'] = [
      '#type'       => 'details',
      '#title'      => $this->t('Export — Drupal → files'),
      '#open'       => TRUE,
      '#attributes' => ['class' => ['js-form-wrapper', 'form-wrapper']],
    ];
    $form['export_details']['description'] = [
      '#type'       => 'html_tag',
      '#tag'        => 'p',
      '#value'      => $this->t(
        'Writes each entity to a <code>.md</code> file in <code>content_export/</code>. Unchanged files are skipped; files for deleted entities are removed.'
      ),
      '#attributes' => ['class' => ['description']],
    ];
    $form['export_details']['content'] = $this->buildChangesPreview($exportPreview, [
      'exported' => ['label' => $this->t('Would write'), 'class' => 'color-warning'],
      'deleted'  => ['label' => $this->t('Would delete'), 'class' => 'color-error'],
    ]);
    $form['export_details']['actions'] = ['#type' => 'actions'];
    $form['export_details']['actions']['export'] = [
      '#type'        => 'submit',
      '#name'        => 'export',
      '#value'       => $this->t('Export all'),
      '#button_type' => 'primary',
      '#disabled'    => $pendingExport === 0 && empty($exportPreview['errors']),
    ];

    // --- Import panel ---
    $form['import_details'] = [
      '#type'       => 'details',
      '#title'      => $this->t('Import — files → Drupal'),
      '#open'       => TRUE,
      '#attributes' => ['class' => ['js-form-wrapper', 'form-wrapper']],
    ];
    $form['import_details']['description'] = [
      '#type'       => 'html_tag',
      '#tag'        => 'p',
      '#value'      => $this->t(
        'Reads every <code>.md</code> file from <code>content_export/</code> and creates or updates the corresponding Drupal entities. Files whose checksum matches are skipped.'
      ),
      '#attributes' => ['class' => ['description']],
    ];
    $form['import_details']['preview'] = $this->buildChangesPreview($importPreview, [
      'imported' => ['label' => $this->t('New'),          'class' => 'color-success'],
      'updated'  => ['label' => $this->t('Updated'),      'class' => 'color-warning'],
      'deleted'  => ['label' => $this->t('Would delete'), 'class' => 'color-error'],
    ]);
    $form['import_details']['actions'] = ['#type' => 'actions'];
    $form['import_details']['actions']['import'] = [
      '#type'        => 'submit',
      '#name'        => 'import',
      '#value'       => $this->t('Import all'),
      '#button_type' => 'primary',
      '#disabled'    => $pendingImport === 0 && empty($importPreview['errors']),
    ];

    $form['export_dir_info'] = $this->buildExportDirInfo();

    return $form;
  }

  // ---------------------------------------------------------------------------
  // Submit handler
  // ---------------------------------------------------------------------------

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $op = $form_state->getTriggeringElement()['#name'] ?? '';

    if ($op === 'export') {
      $result = $this->exporter->exportAll();
      $exp    = count($result['exported']);
      $skp    = count($result['skipped']);
      $del    = count($result['deleted']);
      $err    = count($result['errors']);

      $sections = [
        $this->t('Export @status: @exp written, @skp unchanged, @del deleted.', [
          '@status' => $err ? 'finished with errors' : 'complete',
          '@exp' => $exp, '@skp' => $skp, '@del' => $del,
        ]),
      ];
      if ($err) {
        $sections[] = $this->fileSection($this->t('Errors'), $result['errors']);
      }

      $method = $err ? 'addError' : 'addStatus';
      $this->messenger()->$method($this->joinSections($sections));

      foreach ($result['warnings'] ?? [] as $warning) {
        $this->messenger()->addWarning($warning);
      }
    }
    else {
      $result  = $this->importer->importAll();
      $created = count($result['imported']);
      $updated = count($result['updated']);
      $deleted = count($result['deleted']);
      $skipped = count($result['skipped']);
      $err     = count($result['errors']);

      $sections = [
        $this->t('Import @status: @created created, @updated updated, @deleted deleted, @skipped unchanged.', [
          '@status' => $err ? 'finished with errors' : 'complete',
          '@created' => $created, '@updated' => $updated, '@deleted' => $deleted, '@skipped' => $skipped,
        ]),
      ];
      if ($err) {
        $sections[] = $this->fileSection($this->t('Errors'), $result['errors']);
      }

      $method = $err ? 'addError' : 'addStatus';
      $this->messenger()->$method($this->joinSections($sections));
    }

    // Redirect back to the same page so previews refresh with the new state.
    $form_state->setRedirectUrl(Url::fromRoute('git_content.sync'));
  }

  private function fileSection(mixed $label, array $items): string {
    $lines = implode('<br>', array_map(fn($p) => Html::escape($p), $items));
    return '<strong>' . $label . ':</strong><br>' . $lines;
  }

  private function joinSections(array $sections): Markup {
    return Markup::create(implode('<br><br>', array_map('strval', $sections)));
  }

  // ---------------------------------------------------------------------------
  // Render helpers
  // ---------------------------------------------------------------------------

  private function buildSummary(int $pendingExport, int $pendingImport): array {
    $inSync     = $pendingExport === 0 && $pendingImport === 0;
    $msgType    = $inSync ? 'messages--status' : 'messages--warning';
    $icon       = $inSync ? '✔' : '⚠';
    $statusText = $inSync
      ? $this->t('Everything is in sync.')
      : $this->t('@export pending export(s) · @import pending import(s).', [
          '@export' => $pendingExport,
          '@import' => $pendingImport,
        ]);

    return [
      '#type'       => 'container',
      '#attributes' => [
        'class'      => ['messages', $msgType],
        'role'       => 'status',
        'aria-label' => $inSync ? $this->t('Sync status: in sync') : $this->t('Sync status: changes pending'),
      ],
      'wrapper' => [
        '#type'       => 'container',
        '#attributes' => ['class' => ['messages__wrapper']],
        'text'        => [
          '#markup' => '<span class="messages__icon" aria-hidden="true">' . $icon . '</span>'
            . '<div class="messages__content"><strong>' . $statusText . '</strong></div>',
        ],
      ],
    ];
  }

  /**
   * Render a list of warning strings as a Drupal messages--warning box.
   *
   * @param string[] $warnings
   */
  private function buildWarningsBox(array $warnings): array {
    $lines = implode('<br>', array_map(fn($w) => Html::escape($w), $warnings));
    return [
      '#type'       => 'container',
      '#attributes' => [
        'class'      => ['messages', 'messages--warning'],
        'role'       => 'status',
        'aria-label' => $this->t('Export warnings'),
      ],
      'wrapper' => [
        '#type'       => 'container',
        '#attributes' => ['class' => ['messages__wrapper']],
        'text'        => [
          '#markup' => '<span class="messages__icon" aria-hidden="true">⚠</span>'
            . '<div class="messages__content">' . $lines . '</div>',
        ],
      ],
    ];
  }

  /**
   * Unified preview renderer for both export and import panels.
   *
   * @param array $preview
   *   Result of exporter->previewAll() or importer->previewAll().
   * @param array $ops
   *   Map of preview key → ['label' => ..., 'class' => ...].
   *   e.g. ['exported' => ['label' => 'Would write', 'class' => 'color-warning']]
   */
  private function buildChangesPreview(array $preview, array $ops): array {
    // Group items by entity type.
    // Items can be either file paths (content_types/article/en/foo.md)
    // or entity description strings (node:article: Title (id)) for
    // import deletions returned by syncDeletedEntities().
    $byDir = [];
    foreach ($ops as $key => $info) {
      foreach ($preview[$key] ?? [] as $path) {
        $dir = $this->groupKeyFromPath($path);
        $byDir[$dir][] = ['path' => $path, 'label' => $info['label'], 'class' => $info['class']];
      }
    }

    if (empty($byDir) && empty($preview['errors'])) {
      return [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => '<span aria-hidden="true">✔</span> ' . $this->t('No pending changes.'),
        '#attributes' => ['class' => ['color-success']],
      ];
    }

    $elements = [];

    foreach ($byDir as $dir => $items) {
      $rows = array_map(
        fn($item) => [
          ['data' => Html::escape($item['path'])],
          ['data' => $item['label'], 'class' => [$item['class']]],
        ],
        $items,
      );
      $elements[] = [
        '#type'       => 'details',
        '#title'      => $this->labelFromDir($dir) . ' (' . count($items) . ')',
        '#open'       => FALSE,
        '#attributes' => ['class' => ['js-form-wrapper', 'form-wrapper']],
        'table'       => [
          '#type'       => 'table',
          '#header'     => [$this->t('File'), $this->t('Operation')],
          '#rows'       => $rows,
          '#responsive' => TRUE,
        ],
      ];
    }

    if (!empty($preview['errors'])) {
      $errorRows = array_map(
        fn($e) => [['data' => Html::escape($e)], ['data' => $this->t('Error'), 'class' => ['color-error']]],
        $preview['errors'],
      );
      $elements[] = [
        '#type'       => 'details',
        '#title'      => $this->t('Errors (@count)', ['@count' => count($preview['errors'])]),
        '#open'       => TRUE,
        '#attributes' => ['class' => ['js-form-wrapper', 'form-wrapper', 'color-error']],
        'table'       => [
          '#type'   => 'table',
          '#header' => [$this->t('Message'), $this->t('Type')],
          '#rows'   => $errorRows,
        ],
      ];
    }

    $wrap = ['#type' => 'container', 'items' => $elements];

    $skippedCount = count($preview['skipped'] ?? []);
    if ($skippedCount) {
      $wrap['skipped'] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t('@count file(s) unchanged.', ['@count' => $skippedCount]),
        '#attributes' => ['class' => ['description']],
      ];
    }

    return $wrap;
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Derive a grouping key from a file path or entity description string.
   *
   * Path examples:
   *   content/articles/my-post.es.md  → 'articles'
   *   taxonomy/tags/drupal.es.md      → 'taxonomy'
   *   media/image/photo.es.md         → 'media'
   *   blocks/basic/foo.es.md          → 'blocks'
   *   menus/main/home.es.md           → 'menus'
   *   files/doc.md                    → 'files'
   *   users/admin.md                  → 'users'
   *
   * Entity description strings (import deletions):
   *   "node:article: Title (42)"      → 'articles'
   *   "taxonomy_term:tags: …"         → 'taxonomy'
   */
  private function groupKeyFromPath(string $path): string {
    if (!str_contains($path, '/')) {
      // Entity description string: "entity_type:bundle: …"
      return $this->dirFromEntityType(explode(':', $path)[0] ?? 'other');
    }

    $parts = explode('/', $path);
    $first  = $parts[0] ?? '';
    $second = $parts[1] ?? 'other';

    // Node content lives under content/{bundle_plural}/…
    if ($first === 'content') {
      return $second;
    }

    // Infrastructure dirs use their top-level prefix.
    return in_array($first, ['taxonomy', 'media', 'blocks', 'files', 'users', 'menus'], TRUE)
      ? $first
      : 'other';
  }

  private function dirFromEntityType(string $entityType): string {
    return match ($entityType) {
      'taxonomy_term'     => 'taxonomy',
      'media'             => 'media',
      'block_content'     => 'blocks',
      'file'              => 'files',
      'user'              => 'users',
      'menu_link_content' => 'menus',
      // Nodes: use a generic 'content' key; bundle is unknown from entity type alone.
      default             => 'content',
    };
  }

  private function buildExportDirInfo(): array {
    $dir      = Html::escape($this->exporter->getExportDir());
    $snippet  = Html::escape('$settings[\'git_content_export_dir\'] = \'../my-export\';');
    return [
      '#type'  => 'details',
      '#title' => $this->t('Export directory'),
      '#open'  => FALSE,
      'text'   => [
        '#markup' => Markup::create(
          '<p class="description">'
          . $this->t('Current path:') . ' <code>' . $dir . '</code><br>'
          . $this->t('To override, add the following line to your <code>settings.php</code>:') . '<br>'
          . '<code>' . $snippet . '</code><br>'
          . $this->t('Relative paths are resolved from the Drupal root.')
          . '</p>'
        ),
      ],
    ];
  }

  private function labelFromDir(string $dir): string {
    return (string) match ($dir) {
      'content'  => $this->t('Content'),
      'taxonomy' => $this->t('Taxonomy terms'),
      'media'    => $this->t('Media'),
      'blocks'   => $this->t('Block content'),
      'files'    => $this->t('Files'),
      'users'    => $this->t('Users'),
      'menus'    => $this->t('Menu links'),
      'other'    => $this->t('Other'),
      // Node bundle dirs are plural machine names: articles, pages, sessions…
      default    => ucfirst($dir),
    };
  }

}
