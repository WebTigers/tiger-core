# AGENTS.md — working on the `system` module (platform administration)

Instructions for an AI assistant (or a new contributor) working on Tiger's **system** module. For
platform conventions read the root **AGENTS.md** (`read.guide` with no module). This file is the
module-specific layer; match the surrounding style.

> The platform-administration back office: the **Module manager** (install/activate modules + themes),
> one-click **Updates**, **System Settings**, the **ACL Simulator**, plus two invisible per-user
> services (admin-nav sort, dashboard layout). It manages the *other* modules — so it can never be
> turned off.

## The one thing to know: it's in the PROTECTED set

`System_Service_Modules::PROTECTED = ['default','system','access']` — these can never be deactivated
(core dispatch, the module manager itself, user admin). Anything that would brick the platform if
turned off belongs in that list. `system` is always-on by design.

## Where things live (no models, no routes)

- `controllers/` — `ModulesController` (`/system/modules`), `UpdatesController` (`/system/updates`),
  `SettingsController` (`/system/settings`), `AclController` (`/system/acl`). All admin, all thin.
- `services/` — `Modules`, `Settings`, `Nav`, `Dashboard`, `Acl`.
- `forms/Settings.php`. **No `models/`** — it drives `Tiger_Model_Module` / `Config` / `Option` /
  `UpdateHistory` + the static `Tiger_Module_*`, `Tiger_Update_*`, `Tiger_Dashboard`, `Tiger_Acl_Acl`
  helpers. No `routes.ini` — canonical `system/<controller>/<action>` paths.

## The `/api` surface

- **`System_Service_Modules`** (superadmin) — `activate`/`deactivate` (by `slug`; refuses PROTECTED),
  `search` (Vendor Registry), `inspect` (preview a repo's `module.json` + scrubbed `TIGER.md`, no side
  effects), `install` (from a GitHub `url`/`ref`), `upload` (from a `.zip`).
- **`System_Service_Settings`** (admin) — `save` (session TTL / auto-logout / reCAPTCHA / Location /
  Consent → the `config` table), `locationTest` (live IP-geolocation with the unsaved form values).
- **`System_Service_Nav`** (admin) — `sort` (per-user admin-menu drag order → one
  `tiger.nav.<group>.<key>.sort` config row at **USER scope**; `MAX_KEYS = 60`).
- **`System_Service_Dashboard`** (admin) — `saveLayout`, `saveWidgetPrefs`, `widgetBody`; persists to
  the **lazy `Tiger_Model_Option`** tier (scope=user), not config.
- **`System_Service_Acl`** (superadmin) — `simulate` (`Tiger_Acl_Acl::explain(role,resource,priv)`),
  `catalog` (roles + resources for the pickers). Read-only.

## Conventions + gotchas (this module)

- **Deactivation is a next-request effect.** Toggling flips `module.active`;
  `Tiger_Application_Resource_Modules` re-reads it next request. The current request won't reflect it.
- **Modules are listed from disk, not the runtime map** (`Tiger_Module_Discovery::all()`) — the manager
  must show *inactive* modules too, which are absent from the live map.
- **Themes are in the same manager but activate differently** — `_toggleTheme` writes `tiger.theme`
  config (one active per scope) + symlinks assets; no `active` flag, no build, no deploy.
- **Two per-user stores, deliberately.** Nav sort → `Tiger_Model_Config` at USER scope (folds into the
  request-wide cascade, so the menu partial needs no wiring). Dashboard layout → `Tiger_Model_Option`
  at USER scope (a *lazy* tier, read only when the dashboard renders). Pick the right tier for new
  per-user state — don't bloat the config cascade with private UI state.
- **Convenience state fails soft** — `Nav::sort`, `Dashboard::save*` return success (no-op) on
  malformed/oversized payloads and strip to known keys/ids. Layout is never worth a hard error.
- **Untrusted vendor content is scrubbed** — `inspect()` renders a repo's `TIGER.md` then `_scrub()`s
  (strips script/style/iframe/on*-handlers/`javascript:`) before returning it. It's remote, unreviewed.
- **Self-update is host-dependent + partly advisory** — real Composer on shell hosts, atomic ZIP swap
  on no-shell hosts, else an advisory step. All logged to `Tiger_Log` + `update_history` (history
  failures never break an update).
- **`upload()` bypasses the JSON body** — the archive rides in `$_FILES['archive']` (multipart).

## ACL

superadmin: Modules, Updates, Acl (controllers + services). admin: Settings, Dashboard, Nav.

## Do / Don't

- **Do** add "can't be turned off" modules to `PROTECTED`.
- **Do** put per-user UI state in `Option` (lazy), request-wide settings in `Config` (eager).
- **Don't** expect an activation toggle to take effect this request.
- **Don't** trust vendor/registry content — scrub before rendering.
