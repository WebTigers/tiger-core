# AGENTS.md — working on the `code` module (Tiger Code / the Code Area)

Instructions for an AI assistant (or a new contributor) working on Tiger's **code** module. For
platform conventions — the `/api` service pattern, forms, ACL, models — read the root **AGENTS.md**
(`read.guide` with no module). This file is the module-specific layer; match the surrounding style.

> The Code Area is the in-app tier where a **superadmin** authors PHP (and, by schema, phtml/js/css/
> html) snippets the platform compiles into a cached bundle and runs on every request. Authoring
> server-executed PHP is the **top privilege in the system** — treat this module accordingly.

## The one thing to know: this module is only the UI

The compile / execute / cache **runtime is platform**, not here. `Tiger_Model_Code` (the `code`
table + `lint()` + `save()`), `Tiger_Code_Runtime` (`rebuild()` — assembles + `php -l`-validates one
bundle, cached by the `tiger.code.version` token), and `Tiger_Code_Modules` (static discovery of
file-based module snippets) do the work. This module is a **thin admin screen + `/api` service** on
top. Don't reimplement compilation here; call the engine.

## Where things live

- `controllers/IndexController.php` — `Code_IndexController` (admin, superadmin). `/code` = the
  DataTables list shell; `/code/index/edit[/id/<id>]` = the CodeMirror editor. Render-only.
- `services/Code.php` — `Code_Service_Code`, the whole agent-callable surface.
- `forms/Code.php` — `Code_Form_Code`.
- **No `models/`** — it maps onto `Tiger_Model_Code` + `Tiger_Model_CodeVersion` (platform). No
  `routes.ini`/`navigation.ini`; `Code_Bootstrap` is empty (autoloader only). `/code` is the
  canonical MVC path — no pretty route.

## The `/api` surface (`Code_Service_Code`, superadmin)

- `datatable` — the grid source; **merges two origins** (local `code` rows + live-discovered module
  snippets) and sorts + paginates **in PHP** (no shared DB order). Emits per-row `can_edit` /
  `can_delete` / `can_view` flags.
- `moduleSource` — a module snippet's read-only source, read **live from the file** (never the DB),
  for the "View source" modal. `code_id` is `module:<key>`.
- `save` — create/update a local snippet (insert when `code_id` empty). Validates the form, **lints**
  server languages before storing, saves, then `_safeRebuild()`.
- `activate` / `deactivate` — toggle by `code_id`; lints before activating a server-lang snippet. A
  `module:<key>` id routes to the config active-set, not a `code` row.
- `delete` — soft-delete a local snippet, then rebuild.
- `restore` — restore a prior version (`code_id` + `version`); does **not** auto-reactivate.

## `Code_Form_Code`

`code_id` (hidden), `name` (required, ≤191), `description` (≤255), `language` (php/phtml/html/css/js),
`auto_insert` (head/footer), `priority` (int, default 100), `active` (checkbox), `code` (textarea).
Only `name` is required. **`code` is deliberately not StripTags-filtered** — it's source; it's
validated by a real `php -l` in the service, not the form.

## Conventions + gotchas (this module)

- **Compile-and-cache, not per-snippet include.** Every save/toggle/delete calls
  `Tiger_Code_Runtime::rebuild()` → bumps `tiger.code.version`, recompiles one `php -l`-validated
  bundle, live next request. Never `eval`, never N `require_once`s.
- **The lint gate is mandatory + layered.** `php`/`phtml` (`Tiger_Model_Code::SERVER_LANGS`) are
  linted on save AND activate, and the **whole assembled bundle** is validated before promotion
  (catches cross-snippet redeclares). A parse error is refused and never stored active.
- **`_safeRebuild` self-heals.** If the new active set won't compile, the offending row is marked
  `status=error` (`markError`), the bundle rebuilds so the last-good set keeps serving, and the caller
  gets the compiler error ("Saved, but not activated…"). The module-toggle path rolls the config flag
  back instead.
- **Local vs module snippets are two stores in one grid.** Local = editable `code` rows (UUID
  `code_id`); module = read-only files discovered live (`code_id` = `module:<module>/<file>`), whose
  body is **never** copied into the DB. `_toggle()` branches on the `module:` prefix; module toggles
  flip the `tiger.code.modules` config set.
- **v1 is narrowed in the service, not the form.** Every save forces `org_id = ''` (**platform scope
  — the security boundary**: a tenant can never inject server PHP) and `run_location = LOC_GLOBAL`.
  The form offers more than v1 honors.
- **Read-before-you-run is a product rule.** The View-source modal shows a module snippet's live file
  in read-only CodeMirror, with a "this runs PHP in your app" warning, before activation. Preserve it.
- **View deps:** `tiger.datatable.js`, `TigerButton`, the CodeMirror bundle + `tiger.code-editor.js`
  (`TigerCodeEditor`). CodeMirror mismeasures inside a hidden modal — it's `refresh()`ed on
  `shown.bs.modal`.

## Do / Don't

- **Do** keep the module thin — logic belongs in `Tiger_Code_*` (platform), not here.
- **Do** keep everything superadmin-gated; this is `code.execute`, the god privilege.
- **Don't** copy a module snippet's body into the DB — files are the source of truth.
- **Don't** loosen the lint/compile gate or the `org_id = ''` platform-scope rule — they're the safety
  model, not friction.
