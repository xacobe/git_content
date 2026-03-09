# Git Content module

Export and import Drupal content to and from Markdown files versioned in Git.

## What it does
This module serializes Drupal entities (nodes, taxonomy terms, media, blocks, files, users, and menu links) into Markdown files with YAML frontmatter. The idea is to keep content in a `content_export/` directory that can be versioned in a Git repository.

- **Export**: Reads entities from Drupal and writes `.md` files with human-readable metadata.
- **Import**: Reads `.md` files from `content_export/` and creates/updates Drupal entities.

## Supported entity types
- Nodes (`node`) (includes custom fields, taxonomy, media, and references)
- Taxonomy terms (`taxonomy_term`)
- Media (`media`) (does not copy binaries, only references the file name)
- Block content (`block_content`)
- Files (`file`) (exports metadata only; binaries must be managed separately)
- Users (`user`) (does not export passwords)
- Menu links (`menu_link_content`)

## Export structure
Files are generated under `content_export/` with subfolders by entity type and bundle. For **nodes**, they are grouped first by content type and then by language, so each language has its own folder.

Example:

- `content_export/content_types/article/es/mi-articulo.md`
- `content_export/content_types/article/en/my-article.md`
- `content_export/taxonomy/tags/es/mi-etiqueta.md`
- `content_export/taxonomy/tags/en/my-tag.md`
- `content_export/blocks/basic/es/mi-bloque-12.md`
- `content_export/blocks/basic/en/my-block-12.md`
- `content_export/menus/main/es/inicio.md`
- `content_export/menus/main/es/inicio__segundo-nivel.md`
- `content_export/menus/main/en/home.md`
- `content_export/media/image/...`
- `content_export/files/1234-my-file.md`
- `content_export/users/2-editor.md`

Each `.md` file includes a YAML frontmatter with key fields like `uuid`, `type`, `lang`, `status`, etc., and the content body (for example, the node `body` field) as Markdown.

## UI usage
- **Export**: `/git-content/export` (requires `administer site configuration` permission)
- **Import**: `/git-content/import` (reads `content_export/` and creates/updates content)

## Drush usage
- Export everything: `drush git-content:export` (alias `gce`)
- Export nodes only: `drush git-content:export nodes`
- Export taxonomy only: `drush git-content:export taxonomy`
- Export media only: `drush git-content:export media`
- Import: `drush git-content:import` (alias `gci`)

## Markdown / YAML format
Each `.md` file contains (among other fields) a `checksum` that is used to detect changes and avoid reimporting when nothing has changed:

```md
---
uuid: a1b2c3d4
checksum: 0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33
type: article
lang: es
status: published
...
---

# Content in Markdown
```

The module serializer supports HTML ↔ Markdown conversion using `league/html-to-markdown` and `league/commonmark` when available, and falls back to basic rules otherwise.

## Important notes
- **Binary files** (images, PDFs, etc.) are NOT exported automatically. Only metadata (`uri`, `filename`) is exported to `content_export/files/`. You must manage the binary content separately (e.g., `git-lfs`, `rsync`, etc.).
- **Comments** and related fields (`comment`, `comment_count`, `last_comment_*`, etc.) are **not exported or imported**, since they are typically not used in a static site workflow.
- **User passwords** are not exported. On import, the user with UID 1 is not overwritten to avoid breaking existing credentials.
- The `content_export/` directory must exist at the Drupal root (`DRUPAL_ROOT/content_export`).

---

> This description is generated from the module implementation.
