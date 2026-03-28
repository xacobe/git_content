# Git Content

Export and import Drupal content as Markdown files versioned in Git.

Each entity becomes a `.md` file with YAML frontmatter (metadata) and a Markdown body,
stored in a `content_export/` directory at the Drupal root. Track content changes with
version control, sync between environments, or review diffs on any Git host.

## Requirements

- Drupal 10 or 11
- Core modules: `node`, `taxonomy`, `user`, `file`
- Optional core modules: `media`, `block_content`, `menu_link_content`
- Composer libraries (installed automatically):
  - `league/html-to-markdown ^5.1`
  - `league/commonmark ^2.4`
- Optional: PHP `tidy` extension — pretty-prints exported HTML; the module works without it.

## Installation

```bash
composer require drupal/git_content
drush en git_content
```

The `content_export/` directory is created automatically on first export. To use a different
path, add to `settings.php`:

```php
$settings['git_content_export_dir'] = '../my-export';
```

## Usage

### Admin UI

Visit **Administration > Git Content Sync** (`/admin/git-content`) for a live preview
of pending changes before running an export or import.

### Drush

```bash
drush git-content:export          # export all  (alias: gce)
drush git-content:export nodes    # export one type
drush git-content:import          # import all  (alias: gci)
```

Supported types: `nodes`, `taxonomy`, `media`, `blocks`, `menus`, `files`, `users`.

## Features

- **Multilingual** — default translations are processed before non-default translations.
- **Checksum-based skip** — unchanged files are not re-exported or re-imported.
- **Correct import order** — files → users → taxonomy → media → blocks → nodes → menus.
- **Author preserved** — stored as username, resolved on import across environments.
- **Taxonomy term IDs preserved** where possible so Views filters keep working.
- **Menu link URLs** converted from `entity:node/{id}` to portable `internal:/{alias}`.
- **Auto-generated files excluded** — image style derivatives and oEmbed thumbnails are
  skipped (Drupal regenerates them on render).

## File structure

Language is part of the filename (`{slug}.{lang}.md`):

```
content_export/
  site.yaml
  content/
    article/
      my-article.en.md
      my-article.es.md
  taxonomy/
    tags/
      drupal.en.md
  media/
    image/
      my-image.en.md
  blocks/
    basic/
      my-block-12.en.md
  menus/
    main/
      home.en.md
  files/
    my-file.md
  users/
    editor.md
```

## File format

YAML frontmatter followed by a Markdown body. Fields after `# Drupal` are
Drupal-internal and ignored by static site generators.

```markdown
---
type: article
lang: en
draft: false

title: My article
slug: my-article

date: 2024-01-15
author: editor

path: /my-article

taxonomy:
  field_tags:
    - 12

# Drupal
nid: 42
translation_of: null
checksum: 0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33
---

Body content in **Markdown**.
```

| Field | Description |
|---|---|
| `type` | Entity bundle |
| `lang` | Language code |
| `draft` | `true` = unpublished |
| `date` | Creation date |
| `author` | Owner's Drupal username |
| `path` | URL alias |
| `nid` / `tid` / `mid` | Entity ID (Drupal section) |
| `translation_of` | UUID of default-translation entity (non-default translations only) |
| `checksum` | SHA1 used to skip unchanged files on re-import |

## Submodules

**git_content_layout** — exports Layout Builder per-entity overrides as structured YAML.
Inline blocks are referenced by UUID. Requires `layout_builder`.

**git_content_paragraphs** — embeds Paragraphs inline in the parent `.md` file.
Existing paragraphs are updated in place on re-import. Requires `paragraphs`.

## Notes

- **Binary files** are not exported. Only file metadata is stored; manage binaries
  separately (`git-lfs`, `rsync`, etc.).
- **Passwords** are never exported. User 1 is never overwritten on import.
- **Comments** are not exported or imported.

## Maintainers

- [xacobe](https://www.drupal.org/u/xacobe)
