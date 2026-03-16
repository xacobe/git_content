<?php

namespace Drupal\git_content\Form;

use Drupal\git_content\Exporter\MarkdownExporter;
use Drupal\git_content\Importer\MarkdownImporter;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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

    $pendingImport = count($importPreview['imported'])
      + count($importPreview['updated'])
      + count($importPreview['deleted']);

    $totalEntities = array_sum(array_column($exportPreview, 'entities'));
    $totalFiles    = array_sum(array_column($exportPreview, 'files'));

    // Outer wrapper follows the layout-container pattern used across Drupal
    // admin pages (e.g. config sync, update manager).
    $form['#attributes']['class'][] = 'layout-container';

    $form['summary'] = $this->buildSummary($pendingImport, $totalEntities, $totalFiles);

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
    $form['export_details']['table'] = $this->buildExportTable($exportPreview);
    $form['export_details']['actions'] = ['#type' => 'actions'];
    $form['export_details']['actions']['export'] = [
      '#type'        => 'submit',
      '#name'        => 'export',
      '#value'       => $this->t('Export all'),
      '#button_type' => 'primary',
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
    $form['import_details']['preview'] = $this->buildImportPreview($importPreview);
    $form['import_details']['actions'] = ['#type' => 'actions'];
    $form['import_details']['actions']['import'] = [
      '#type'        => 'submit',
      '#name'        => 'import',
      '#value'       => $this->t('Import all'),
      '#button_type' => 'primary',
      '#disabled'    => $pendingImport === 0 && empty($importPreview['errors']),
    ];

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

      if ($err) {
        $this->messenger()->addError($this->t(
          'Export finished with @err error(s): @exp written, @skp unchanged, @del deleted.',
          ['@exp' => $exp, '@skp' => $skp, '@del' => $del, '@err' => $err]
        ));
        foreach ($result['errors'] as $error) {
          $this->messenger()->addError($error);
        }
      }
      else {
        $this->messenger()->addStatus($this->t(
          'Export complete: @exp written, @skp unchanged, @del deleted.',
          ['@exp' => $exp, '@skp' => $skp, '@del' => $del]
        ));
      }
    }
    else {
      $result  = $this->importer->importAll();
      $created = count($result['imported']);
      $updated = count($result['updated']);
      $deleted = count($result['deleted']);
      $skipped = count($result['skipped']);
      $err     = count($result['errors']);

      if ($err) {
        $this->messenger()->addError($this->t(
          'Import finished with @err error(s): @created created, @updated updated, @deleted deleted, @skipped unchanged.',
          ['@created' => $created, '@updated' => $updated, '@deleted' => $deleted, '@skipped' => $skipped, '@err' => $err]
        ));
        foreach ($result['errors'] as $error) {
          $this->messenger()->addError($error);
        }
      }
      else {
        $this->messenger()->addStatus($this->t(
          'Import complete: @created created, @updated updated, @deleted deleted, @skipped unchanged.',
          ['@created' => $created, '@updated' => $updated, '@deleted' => $deleted, '@skipped' => $skipped]
        ));
      }
    }

    // Redirect back to the same page so previews refresh with the new state.
    $form_state->setRedirectUrl(Url::fromRoute('git_content.sync'));
  }

  // ---------------------------------------------------------------------------
  // Render helpers
  // ---------------------------------------------------------------------------

  private function buildSummary(int $pendingImport, int $totalEntities, int $totalFiles): array {
    $inSync     = $pendingImport === 0;
    $msgType    = $inSync ? 'messages--status' : 'messages--warning';
    $icon       = $inSync ? '✔' : '⚠';
    $statusText = $inSync
      ? $this->t('Everything is in sync.')
      : $this->t('@count pending change(s) to import from files.', ['@count' => $pendingImport]);

    return [
      '#type'       => 'container',
      '#attributes' => [
        'class' => ['messages', $msgType],
        'role'  => 'status',
        'aria-label' => $inSync ? $this->t('Sync status: in sync') : $this->t('Sync status: changes pending'),
      ],
      'wrapper' => [
        '#type'       => 'container',
        '#attributes' => ['class' => ['messages__wrapper']],
        'text' => [
          '#markup' => '<span class="messages__icon" aria-hidden="true">' . $icon . '</span>'
            . '<div class="messages__content">'
            . '<strong>' . $statusText . '</strong>'
            . ' <span class="description">'
            . $this->t('@entities entities in Drupal · @files files on disk', [
              '@entities' => $totalEntities,
              '@files'    => $totalFiles,
            ])
            . '</span>'
            . '</div>',
        ],
      ],
    ];
  }

  private function buildExportTable(array $preview): array {
    $labels = [
      'node'              => $this->t('Nodes'),
      'taxonomy_term'     => $this->t('Taxonomy terms'),
      'media'             => $this->t('Media'),
      'block_content'     => $this->t('Block content'),
      'file'              => $this->t('Files'),
      'user'              => $this->t('Users'),
      'menu_link_content' => $this->t('Menu links'),
    ];

    $rows = [];
    foreach ($preview as $entity_type => $counts) {
      if ($counts['entities'] === 0 && $counts['files'] === 0) {
        continue;
      }
      $diff = $counts['entities'] - $counts['files'];
      if ($diff > 0) {
        $status = [
          'data'  => $this->t('+@n to export', ['@n' => $diff]),
          'class' => ['color-warning'],
        ];
      }
      elseif ($diff < 0) {
        $status = [
          'data'  => $this->t('@n stale file(s)', ['@n' => abs($diff)]),
          'class' => ['color-warning'],
        ];
      }
      else {
        $status = [
          'data'  => $this->t('In sync'),
          'class' => ['color-success'],
        ];
      }
      $rows[] = [
        ['data' => $labels[$entity_type] ?? $entity_type],
        ['data' => $counts['entities']],
        ['data' => $counts['files']],
        $status,
      ];
    }

    return [
      '#type'        => 'table',
      '#header'      => [
        $this->t('Type'),
        $this->t('In Drupal'),
        $this->t('On disk'),
        $this->t('Status'),
      ],
      '#rows'        => $rows,
      '#empty'       => $this->t('No content found.'),
      '#responsive'  => TRUE,
      '#attributes'  => ['class' => ['responsive-enabled']],
    ];
  }

  private function buildImportPreview(array $preview): array {
    // Each operation type maps to a label and a Drupal color utility class.
    $ops = [
      'imported' => ['label' => $this->t('New'),          'class' => 'color-success'],
      'updated'  => ['label' => $this->t('Updated'),      'class' => 'color-warning'],
      'deleted'  => ['label' => $this->t('Would delete'), 'class' => 'color-error'],
    ];

    $elements = [];

    foreach ($ops as $op => $info) {
      if (empty($preview[$op])) {
        continue;
      }
      $grouped = [];
      foreach ($preview[$op] as $path) {
        $grouped[$this->detectTypeFromPath($path)][] = $path;
      }
      foreach ($grouped as $type => $files) {
        $title = $info['label'] . ' — ' . $this->labelForType($type) . ' (' . count($files) . ')';
        $elements[] = [
          '#type'       => 'details',
          '#title'      => $title,
          '#open'       => FALSE,
          '#attributes' => [
            'class' => ['js-form-wrapper', 'form-wrapper', $info['class']],
          ],
          'list' => [
            '#theme'              => 'item_list',
            '#items'              => array_map(fn($f) => Html::escape($f), $files),
            '#wrapper_attributes' => ['class' => ['item-list']],
          ],
        ];
      }
    }

    if (!empty($preview['errors'])) {
      $elements[] = [
        '#type'       => 'details',
        '#title'      => $this->t('Errors (@count)', ['@count' => count($preview['errors'])]),
        '#open'       => TRUE,
        '#attributes' => ['class' => ['js-form-wrapper', 'form-wrapper', 'color-error']],
        'list'        => [
          '#theme'              => 'item_list',
          '#items'              => array_map(fn($e) => Html::escape($e), $preview['errors']),
          '#wrapper_attributes' => ['class' => ['item-list']],
        ],
      ];
    }

    if (empty($elements)) {
      return [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => '<span aria-hidden="true">✔</span> ' . $this->t('No pending changes — files are up to date with Drupal.'),
        '#attributes' => ['class' => ['color-success']],
      ];
    }

    $skippedCount = count($preview['skipped'] ?? []);
    if ($skippedCount) {
      $elements[] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t('@count file(s) unchanged (checksum match).', ['@count' => $skippedCount]),
        '#attributes' => ['class' => ['description']],
      ];
    }

    return [
      '#type'       => 'container',
      '#attributes' => ['class' => ['js-form-wrapper']],
      'items'       => $elements,
    ];
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private function detectTypeFromPath(string $path): string {
    return match (explode('/', $path)[0] ?? '') {
      'content_types' => 'nodes',
      'taxonomy'      => 'taxonomy',
      'media'         => 'media',
      'blocks'        => 'blocks',
      'files'         => 'files',
      'users'         => 'users',
      'menus'         => 'menus',
      default         => 'other',
    };
  }

  private function labelForType(string $type): string {
    return (string) match ($type) {
      'nodes'    => $this->t('Nodes'),
      'taxonomy' => $this->t('Taxonomy'),
      'media'    => $this->t('Media'),
      'blocks'   => $this->t('Block content'),
      'files'    => $this->t('Files'),
      'users'    => $this->t('Users'),
      'menus'    => $this->t('Menu links'),
      default    => $this->t('Other'),
    };
  }

}
