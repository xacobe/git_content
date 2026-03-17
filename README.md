# Git Content

Export and import Drupal content to Markdown files that can be versioned in Git.

Each entity is serialized as a `.md` file with YAML frontmatter containing metadata and the body as Markdown. Files live in a `content_export/` directory at the Drupal root, making it straightforward to track content changes, sync between environments, or review diffs in any Git host.

## Features

- Export and import nodes, taxonomy terms, media, block content, files, users, and menu links
- Multilingual: default translations are imported before non-default to preserve the original language
- Checksum-based skip: unchanged files are not re-imported
- Stale reference detection: forces re-import when a referenced entity has been deleted
- Author preserved: `uid` is stored as a username and resolved on import
- Taxonomy term IDs preserved where possible so Views filters keep working
- Menu link attributes (icons, CSS classes) round-tripped via `link_options`
- Menu link URLs converted from environment-specific `entity:node/{id}` to portable `internal:/{alias}`
- Drush commands for CI/CD workflows

## Requirements

- Drupal 10 or 11
- Required core modules: `node`, `taxonomy`, `user`, `file`
- Optional core modules: `media`, `block_content`, `menu_link_content` (supported when enabled)
- Composer libraries (installed automatically via `composer require drupal/git_content`):
  - `league/html-to-markdown ^5.1`
  - `league/commonmark ^2.4`
- Optional PHP extension: `tidy` — when available, `full_html` text fields are exported as
  human-readable indented HTML. Without it the HTML is still exported correctly, just on a
  single line. See [Optional: tidy extension](#optional-tidy-extension) below.

## Installation

1. Place the module in `modules/custom/git_content/` (or install via Composer).
2. Enable the module: `drush en git_content`
3. Optionally enable submodules for Layout Builder or Paragraphs support.
4. Create the export directory at `DRUPAL_ROOT/content_export/` (the module will create it on first export).

## Submodules

### git_content_layout

Extends git_content to export and import **Layout Builder** per-entity overrides.

- Exports each layout section and its components as structured YAML
- Inline block references are stored as UUIDs for portability across environments
- Requires `drupal:layout_builder`

### git_content_paragraphs

Extends git_content to export and import **Paragraphs** field values inline within their parent entity.

- Paragraph data is embedded directly in the parent `.md` file
- Existing paragraphs are updated in place on re-import
- Requires `drupal:paragraphs`

## Usage

### UI

| Path | Description |
|------|-------------|
| `/git-content/export` | Export all content to `content_export/` |
| `/git-content/import` | Preview and import from `content_export/` |

Both pages require the `administer site configuration` permission.

### Drush

```bash
# Export everything
drush git-content:export        # alias: gce

# Export a specific entity type
drush git-content:export nodes
drush git-content:export taxonomy
drush git-content:export media
drush git-content:export blocks
drush git-content:export menus
drush git-content:export files
drush git-content:export users

# Import everything
drush git-content:import        # alias: gci
```

## File structure

Files are organized under `content_export/` by entity type, bundle, and language:

```
content_export/
  content_types/
    article/
      es/my-article.md
      en/my-article.md
  taxonomy/
    tags/
      es/my-tag.md
  blocks/
    basic/
      es/my-block-12.md
  menus/
    main/
      es/inicio.md
      es/inicio--segundo-nivel.md
  media/
    image/
      es/my-image.md
  files/
    my-file.md
  users/
    editor.md
```

## File format

Each `.md` file has YAML frontmatter followed by a Markdown body. Example node:

```markdown
---
uuid: a1b2c3d4-e5f6-7890-ab12-cdef01234567
type: article
lang: es
status: published

title: My article
slug: my-article

created: 2024-01-15
changed: 2024-03-10
author: editor

path: /my-article

taxonomy:
  tags:
    - Drupal
    - Content

translation_of: ~
checksum: 0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33
---

Body content in **Markdown**.
```

Key frontmatter fields:

| Field | Description |
|-------|-------------|
| `uuid` | Full entity UUID — used to match entities on re-import |
| `type` | Entity type or bundle |
| `lang` | Language code |
| `status` | `published` / `draft` / `active` / `blocked` / `permanent` |
| `author` | Account name of the entity owner (nodes and media) |
| `translation_of` | UUID of the default translation; present only on non-default translations |
| `checksum` | SHA1 of the frontmatter + body; skips import when unchanged |

## Important notes

- **Binary files** are not exported. Only file metadata (`uri`, `filename`, `mime`) is stored. Manage binaries separately (e.g. `git-lfs`, `rsync`).
- **Passwords** are never exported. On import, user 1 is not overwritten to protect production credentials.
- **Comments** are not exported or imported.
- **Import order** is handled automatically: files, users, taxonomy, and media are imported before nodes; default translations before non-default translations within the same entity.

## Install libraríes (if module not installed from drupal.org) 
composer require league/html-to-markdown:^5.1 league/commonmark:^2.4


## Optional: tidy extension

The PHP [`tidy`](https://www.php.net/manual/en/book.tidy.php) extension pretty-prints HTML
exported from `full_html` text fields, making it easier to read and edit in GitHub.
The module works without it — HTML is just exported on a single line.

### DDEV

Add to `.ddev/config.yaml` (adjust PHP version to match your project):

```yaml
webimage_extra_packages:
  - php8.3-tidy   # or php8.4-tidy, php8.2-tidy, etc.
```

Then restart:

```bash
ddev restart
```

### Lando

Add a build step to `.lando.yml`:

```yaml
services:
  appserver:
    build:
      - apt-get install -y php8.3-tidy
```

Then rebuild:

```bash
lando rebuild
```

### Verify

```bash
php -r 'var_dump(extension_loaded("tidy"));'
# Expected: bool(true)
```

## Maintainers

- [xacobe](https://www.drupal.org/u/xacobe)
