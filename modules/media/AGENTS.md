# AGENTS.md — working on the `media` module

Instructions for an AI assistant (or a new contributor) working on Tiger's **media** module. For
platform conventions read the root **AGENTS.md** (`read.guide` with no module). This file is the
module-specific layer; match the surrounding style.

> The admin **Media Library** — a drag-drop uploader + DataTables grid over the platform media engine
> (`Tiger_Model_Media`, `Tiger_Media_Storage`, `Tiger_Media_Image`, `Tiger_Media_Scan`). The module is
> thin; storage, metadata, image variants, and scanning live in the `Tiger_*` engine.

## The one thing to know: public vs private serving

Public files get a **direct URL served by the web server**. Private files **stream through
`Media_FileController::serveAction`** (`/media/file/serve/id/<id>[/v/<variant>]`) behind an ACL check +
a belt-and-suspenders org-scope guard (`identity.org_id === media.org_id`). Cache headers differ
(`public, max-age=31536000` vs `private, no-store`). Never let a private object get a direct URL.

## Where things live (no models of its own)

- `controllers/` — `IndexController` (`/media`, the Library shell), `AdminController`
  (`/media/admin/settings`), `FileController` (private streaming), `CallbackController`
  (`/media/callback`, **guest** — AWS SNS video-moderation endpoint).
- `services/` — `Media`, `Settings`. `forms/Settings.php`.
- **No `models/`** — `Tiger_Model_Media` + the `Tiger_Media_*` engine + `Tiger_Model_Config`.

## The `/api` surface (admin)

- **`Media_Service_Media`** — `upload` (ONE file in `$_FILES['file']`: validate → classify →
  MIME-sniff → optional scan → store → insert row → variants → return the presented row), `datatable`
  (grid, `kind` filter, per-row `can_delete`), `update` (title/caption/alt_text/visibility), `delete`
  (soft-delete row + delete bytes + variants).
- **`Media_Service_Settings`** — `save`: write the two obfuscation flags to config (org-scoped).

## Conventions + gotchas (this module)

- **Storage key layout:** `<org>/<kind>/<base>.<ext>`, keyed by the **immutable `org_id`** (a slug
  rename would orphan files); org-less uploads go under `_shared`. `base` is random hex when this org
  obfuscates that visibility, else a slugified filename + short random suffix.
- **One file per upload request** — `upload()` reads a single `$_FILES['file']` (so the client can show
  per-file progress); the file rides in `$_FILES`, not `$params`.
- **Variants are best-effort, two paths.** Server-side GD (`Tiger_Media_Image`) when GD is present AND
  `media.variants.server` is on; otherwise the browser posts a pre-made `$_FILES['thumbnail']` (the
  controller sets `clientThumb` to tell the uploader). Variant failure is non-fatal — the original is
  kept.
- **Orphan-safety on failure** — if the DB insert throws after storing bytes, the object is deleted; if
  variant generation throws, the upload survives.
- **Scanning is config-gated and OFF by default.** ClamAV virus + AI image review run pre-store
  (rejects store nothing). **Video AI review is async** — a video is stored `private` +
  `scan_status=in_review` and only unlocked when the SNS callback approves it, but that callback is a
  **P4 scaffold** (SNS signature verification is a TODO — nothing flips the verdict yet).
- **Config keys are top-level `media.*`, not `tiger.media.*`** (`media.max_upload`,
  `media.variants.*`, `media.scan.*`); obfuscation flags via `Tiger_Model_Media::CFG_OBFUSCATE`,
  org-scoped. Settings are config rows (config-discipline), never a table.
- **Registration:** `Bootstrap::_initAdminSettings()` (Settings-tree, key `media`, `fa-photo-film`,
  order 40).

## ACL

admin: `Media_IndexController`, `Media_AdminController`, `Media_FileController`, `Media_Service_Media`,
`Media_Service_Settings`. **guest**: `Media_CallbackController` (the SNS endpoint carries no session).

## Do / Don't

- **Do** stream private files through `FileController`; **do** key storage by `org_id`, not slug.
- **Don't** trust `Media_CallbackController` yet — its SNS verification is unbuilt (P4).
- **Don't** fail an upload because a variant/thumbnail failed — keep the original.
