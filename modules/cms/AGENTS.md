# AGENTS.md — working on the `cms` module

Instructions for an AI assistant (or a new contributor) working on Tiger's **cms** module. For
platform conventions read the root **AGENTS.md** (`read.guide` with no module). This file is the
module-specific layer; match the surrounding style.

> The authoring UI for page content — a content list, a page editor, a GrapesJS visual builder, a
> Menus builder, and a site-Settings screen — plus the public `/cms` marketing landing. The CMS
> *engine* is platform (`Tiger_Model_Page`, `Tiger_Cms_Renderer`, the PageDispatch plugin); this
> module is the admin surface on top.

## The one thing to know: there is no store

Pages, **layouts, and partials are all `page` rows** discriminated by `type`
(`TYPE_PAGE`/`TYPE_LAYOUT`/`TYPE_PARTIAL`) — an article (`type=article`) is the same table. Never add
a CMS table; write through `Tiger_Model_Page::save()` (transactional, version snapshot, slug 301).

## Where things live (no models, no routes)

- `controllers/IndexController.php` — public `/cms` landing (guest).
- `controllers/PageController.php` — `/cms/page` (list), `/cms/page/edit`, `/cms/page/design` (the
  full-screen GrapesJS builder; `disableLayout()`).
- `controllers/MenuController.php` — `/cms/menu` menus builder. `controllers/SettingsController.php` —
  `/cms/settings`.
- `services/` — `Page`, `Menu`, `Settings`. `forms/` — `Page`, `MenuItem`, `Settings`.
- **No `models/`** (uses `Tiger_Model_Page`/`PageVersion`/`Menu`/`Config` + `Tiger_Theme`). No
  `routes.ini` — canonical `cms/<controller>/<action>` paths.

## The `/api` surface (admin+, except the guest landing)

- **`Cms_Service_Page`** — `datatable`, `save`, `saveDesign` (GrapesJS output → `format=builder`,
  strips `<script>`), `forkTheme` (copy a theme file-template into an editable DB page that overrides
  it), `delete`, `restore`.
- **`Cms_Service_Menu`** — `datatable`, `save` (one item), `delete` (item+subtree), `deleteMenu`,
  `reorder` (drag-drop tree, batch re-parent+re-sort).
- **`Cms_Service_Settings`** — `save` (`tiger.site.name`, `tiger.site.home_page` → config, global).

## Conventions + gotchas (this module)

- **Slug/key rules.** Pages get a slug (input, else slugified title); **layouts/partials get
  `slug = NULL`** (not `''`) to satisfy `UNIQUE(org_id, slug, locale)`. A `page_key` is ALWAYS set
  (stable handle).
- **`meta` is a JSON blob with a fixed shape** — SEO under `meta.seo.description` (flat legacy
  `meta.description` was migrated out, 0032); `meta.head_html` + `meta.body_scripts` are the flat,
  admin-trusted output-verbatim escape hatch; `meta.builder` holds the lossless GrapesJS JSON;
  `meta.source`/`source_key` tag theme-forked pages. **`save()` and `saveDesign()` read-merge-write** —
  each preserves the other's meta keys; never overwrite the whole blob.
- **WAF/base64 shim (page editor only).** `body`, `head_html`, `body_scripts` post as `body_b64`/etc.
  (chunked UTF-8 `btoa`) so the shared ALB WAF doesn't 403 on raw `<script>`/HTML/PHP;
  `Tiger_Service_Service` decodes `*_b64` transparently. Add a code-bearing field → apply the same.
  (The blog editor does **not** do this.)
- **Two editors.** The normal editor is **CodeMirror** (`TigerCodeEditor.syncAll()` flushes → textarea
  before post); `designAction` is a separate **GrapesJS** full-screen builder (`format=builder`, a
  SAFE format — `saveDesign` strips all `<script>`). Metadata (title/slug/status) is edited in the
  normal editor, never the builder.
- **Formats:** `html | markdown | phtml (trusted code) | builder`. `forkTheme` picks `phtml` if the
  template body has `<?php`/`<?=`, else `html`. **Theme files are never modified** — a fork copies into
  a DB page at the same slug that overrides the file (live-override tier).
- **Menu items are tokenless by design** (`Cms_Form_MenuItem::csrf() = false`) for SPA-style rapid
  saves; a link is a `page_key` (resolved to the live slug at render) OR a literal `url` (page wins).

## ACL

guest: `Cms_IndexController` (the public landing). admin: Page/Menu/Settings controllers + services.

## Do / Don't

- **Do** write through `Tiger_Model_Page`; **do** read-merge-write `meta`.
- **Don't** add a table, **don't** give a layout/partial an empty-string slug, **don't** forget the
  `*_b64` shim on a new code-bearing field.
