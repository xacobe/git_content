# Módulo Git Content

Exporta e importa contenido de Drupal a/desde archivos Markdown versionables en Git.

## ¿Qué hace?
Este módulo permite serializar entidades de Drupal (nodos, términos de taxonomía, media, bloques, archivos, usuarios y enlaces de menú) en archivos Markdown con *frontmatter* YAML. La idea es mantener el contenido en un directorio `content_export/` que puede versionarse en un repositorio Git.

- **Exportación**: Lee entidades desde Drupal y genera `.md` con metadata legible.
- **Importación**: Lee `.md` desde `content_export/` y crea/actualiza entidades en Drupal.

## Tipo de entidades soportadas
- Nodos (`node`) (incluye campos personalizados, taxonomía, media y referencias)
- Términos de taxonomía (`taxonomy_term`)
- Media (`media`) (no copia los binarios, solo referencia el nombre de archivo)
- Bloques de contenido (`block_content`)
- Archivos (`file`) (exporta solo metadatos; el binario debe gestionarse por separado)
- Usuarios (`user`) (no exporta contraseñas)
- Enlaces de menú (`menu_link_content`)

## Estructura de exportación
Los archivos se generan en `content_export/` con subcarpetas por tipo y bundle. Para los **nodos**, se agrupan primero por tipo de contenido y luego por idioma, de modo que cada idioma tenga su propia carpeta.

Ejemplo:

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
- `content_export/files/1234-mi-archivo.md`
- `content_export/users/2-editor.md`

Cada `.md` tiene un frontmatter YAML con campos clave como `uuid`, `type`, `lang`, `status`, etc., y el cuerpo del contenido (por ejemplo, el campo `body` del nodo) como Markdown.

## Uso (UI)
- **Exportar**: `/git-content/export` (requiere permiso `administer site configuration`)
- **Importar**: `/git-content/import` (lee `content_export/` y crea/actualiza contenido)

## Uso (Drush)
- Exportar todo: `drush git-content:export` (alias `gce`)
- Exportar solo nodos: `drush git-content:export nodes`
- Exportar solo taxonomía: `drush git-content:export taxonomy`
- Exportar solo media: `drush git-content:export media`
- Importar: `drush git-content:import` (alias `gci`)

## Formato Markdown / YAML
Cada archivo `.md` contiene (entre otros campos) un `checksum` que permite detectar cambios y evitar reimportar cuando no se han realizado modificaciones:

```md
---
uuid: a1b2c3d4
checksum: 0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33
type: article
lang: es
status: published
...
---

# Contenido en Markdown
```

El serializer del módulo admite conversión HTML ↔ Markdown usando `league/html-to-markdown` y `league/commonmark` si están disponibles, y usa reglas básicas si no.

## Notas importantes
- **Archivos binarios** (imágenes, PDFs, etc.) NO se exportan automáticamente. Se exportan solo metadatos (`uri`, `filename`) en `content_export/files/`. Debes manejar el versionado del binario (por ejemplo, `git-lfs`, `rsync`, etc.).
- **Comentarios** y campos relacionados (`comment`, `comment_count`, `last_comment_*`, etc.) **no se exportan ni se importan**, ya que en un sitio estático gestionado por Tome no se usan.
- **Contraseñas de usuario** no se exportan. Al importar, el usuario con UID 1 no se sobrescribe para evitar romper credenciales existentes.
- El directorio `content_export/` debe existir en la raíz del sitio Drupal (`DRUPAL_ROOT/content_export`).

---

> Esta descripción se genera automáticamente a partir de la implementación del módulo.
