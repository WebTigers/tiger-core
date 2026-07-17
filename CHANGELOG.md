# Changelog

All notable changes to **Tiger Core** (`webtigers/tiger-core`). Format follows
[Keep a Changelog](https://keepachangelog.com/); this project uses [SemVer](https://semver.org/)
— while `0.x`, the public API (`@api`) may still shift between minor versions.

## [0.13.1-beta] — 2026-07-17

### Fixed
- **Dashboard "Customize" button did nothing.** The dashboard's inline script runs at parse time, but
  Bootstrap's JS loads in the layout footer *after* it — so `new bootstrap.Modal()` was called against an
  undefined `window.bootstrap`, leaving the modal null and the click handler unattached. Now the modal is
  created lazily on first click (when Bootstrap is loaded), matching how every other admin view does it.

## [0.13.0-beta] — 2026-07-17

### Added
- **Customize the dashboard — hide/show widgets per user (WP "Screen Options" style).** A **Customize**
  button (top-right, where the redundant breadcrumb was) opens a modal listing every widget the user can
  see, each with an on/off switch. A hidden widget is **not rendered** (its body does no work) — it stays
  in the page as a lightweight header shell out of the Muuri grid; switching it back on fetches its body
  via `/api` (`System_Service_Dashboard::widgetBody`) and drops the card into the grid **live, no reload**.
  The hidden set persists per-user in the lazy option tier (`tiger.dashboard.prefs`) alongside the
  existing layout. Also **removed the dashboard breadcrumb**.
- **"Refresh directory" button in the Module Manager.** The registry index is cached ~3h, so a newly
  published listing could take that long to appear under Modules → Add New. A refresh control now
  re-fetches the catalog (and the sponsored overlay) on demand — `Tiger_Module_Registry::index()` /
  `search()` / `available()` / `sponsored()` take a `$refresh` flag, threaded through
  `System_Service_Modules::search` (`refresh` param) to the Browse view's button.

## [0.12.1-beta] — 2026-07-17

### Fixed
- **`Tiger_Module_Github`: dropped the deprecated `curl_close()`.** On PHP 8+ the `CurlHandle` is freed
  by GC when the handle leaves scope, so the call was already a no-op — and PHP 8.5 emits `E_DEPRECATED`
  for it. Removing the dead line silences the notice (which the update checker's GitHub fetches were
  tripping) with no behavior change on 8.1–8.5.

## [0.12.0-beta] — 2026-07-17

### Added
- **Update screen — "What's new" per update.** The Updates screen now shows each offered version's
  changelog section beside the version bump. `Tiger_Update_Checker::notes()` fetches the target's
  `CHANGELOG.md` from its repo **at the new release ref** (not the installed copy) and slices out the
  `## [<version>]` section; the controller attaches it to each pending item and the view renders it in a
  collapsible "What's new in x.y.z". Tries the tag first, then the default branch (`main`/`master`) — a
  tag's tree can predate its changelog entry, and the offered version always sits at the top of the
  branch. File-cached by version, fail-soft to version-numbers-only when a repo ships no changelog or the
  section isn't found. One source of truth — the `CHANGELOG.md` every Tiger repo already maintains (no
  separate release-notes file).

## [0.11.1-beta] — 2026-07-16

### Changed
- **Module registry points at the renamed repos** — `Tiger_Module_Registry` now reads its index from
  `WebTigers/TigerVendors` and its sponsored overlay from `WebTigers/TigerSponsors` (both renamed for
  naming consistency, every repo `Tiger*`). Config-overridable via `tiger.modules.registry` /
  `tiger.modules.sponsors`.

## [0.11.0-beta] — 2026-07-16

### Added
- **Location settings screen** (System → Location) — selectable geocoding/IP adapters
  (Nominatim / AWS Location / ip-api) with per-adapter fields, **secrets encrypted at rest**
  (`Tiger_Crypto`), a per-provider+IP APCu cache, and a live "test" button. The `Tiger_Location` facade
  is now admin-configurable with no deploy.
- **Login: most-targeted accounts** — `Tiger_Model_Login::topFailures()` surfaces the accounts taking
  the most failed sign-ins (feeds the security dashboard widget).

### Changed
- Dashboard widget collapse animates via `TigerDOM` (no `d-none` swap); the whole card header is the
  drag handle, with a collapse toggle (security-plugin-style chrome).

## [0.10.0-beta] — 2026-07-16

### Added
- **Dashboard widget platform API.** A module-registered widget registry (`Tiger_Dashboard`) renders on
  an even-column, collapsible **Muuri** drag-drop grid, with each user's layout persisted in the lazy
  scoped **option store** (`Tiger_Model_Option`, migration 0031 — on-demand per-user/entity state, not
  eager config). Modules add a dashboard card from their Bootstrap with no core edit; TigerShield's
  shield card is the first consumer.

## [0.9.0-beta] — 2026-07-16

### Added
- **reCAPTCHA settings screen** (System) — a first-party admin surface over the reCAPTCHA controls, with
  a shared `Tiger_Recaptcha` reader/writer so other screens (e.g. TigerShield) stay in lockstep. The
  site key is public; the secret lives in `local.ini`.

### Fixed
- Release-ZIP CI hardening — `--clobber` the vendored-zip upload, pin the checkout to the tag, strip the
  vendored `.git` from the artifact; the composer update path seeds a safe `gitconfig` so a `vendor/`
  ownership mismatch stays quiet.

## [0.8.1-beta] — 2026-07-16

### Added
- **The Updates screen actually applies the platform update** — Composer where it can run, an atomic
  `vendor/`-ZIP swap where it can't (the no-shell / cPanel path).

### Fixed
- Update backups are kept **out of** `modules/`, and a stray directory can never brick boot.
- `build-app-bundle` strips the vendored `.git` (a source-install artifact).

## [0.8.0-beta] — 2026-07-16

### Added
- **Friendly `/login` + `/logout` aliases** and a signed-out confirmation page.

## [0.7.1-beta] — 2026-07-15

### Added
- **Public `/cms` landing** — positioning Tiger as the modern WordPress alternative, with **two install
  paths**: a one-file cPanel web installer and Composer. The installer downloads as a direct `.zip`
  (no GitHub bounce); ships the web-installer design-of-record + the full-app bundle build script.

### Changed
- **`ext-sodium` is no longer required** — `Tiger_Crypto` bundles `paragonie/sodium_compat`, so
  libsodium-dependent features (encryption at rest, TOTP) run on hosts without the extension.

## [0.7.0-beta] — 2026-07-15

A large release: installable themes, the CMS page builder, the Media Library, the Add-Module directory,
and the Code Area all landed or were rebuilt. See [THEMES.md](THEMES.md), [MEDIA.md](MEDIA.md), and
[CODE.md](CODE.md) for the design-of-record docs.

### Added
- **Installable themes (theme-as-a-module).** A theme shipped as a `theme-<name>` module resolves as the
  active theme (path-based, no build); **file-based theme pages** (`content/*.phtml`) + **builder
  components** with a self-describing `tiger:page` / `tiger:block` hint; per-page layout selection
  (including `layout="none"` for a verbatim page); **context-aware resolution** (admin/auth fall back to
  the base theme so a public theme can never break the back office); and Module-Manager **activation**
  (preview → activate → asset symlink). Home falls back to the active theme's `content/index.phtml`.
- **CMS page builder (GrapesJS).** A Bootstrap 5 block library, live menu blocks, a head/scripts editor,
  a Tiger-palette reskin + uniform block icons, and Image/Video blocks wired to the Media Library. The
  CMS code editor gains CodeMirror `htmlmixed` (color + folding), a resizable pane, a Word-style find
  bar, and a one-click Reformat.
- **Theme templates in the CMS** — surface the active theme's page templates with **fork-on-edit**
  (customize a theme page without touching its file), split across Pages / Theme Templates tabs.
- **Media Library, rebuilt on TigerUpload** — masonry portfolio, wider thumbnails + a Dimensions column,
  a shared Edit modal (title/caption/alt/visibility), Copy-URL, one-click Delete, and full-page
  drag-drop; plus **per-org filename obfuscation** (public/private) with a settings screen.
- **Add-Module directory revamp** — tabs, upload + drag-drop install, a type filter, free/pro badges,
  installed state, Muuri masonry, a full-height hero, a screenshot gallery + lightbox, and self-hosted /
  YouTube / Vimeo video; themes preview + install via the registry (`theme.json`).
- **Code Area + code modules** — discovery recognizes a `code` module (`module.json` + `snippets/`); a
  module-snippet importer keeps files as the source of truth (never copied to the DB); a read-only
  CodeMirror view-source modal.
- **TigerUpload vendored** — a headless upload engine (one upload → N independent renderer subscribers);
  the Add-Module upload and the Media Library both ride it.
- Persisted **update history** + a version-tag CI assert.

### Changed
- **PUMA upgraded to Bootstrap 5.3.8** (readable, non-minified).
- The sponsored-placement overlay moved to its own repo.

### Fixed
- PUMA: headings use `--bs-primary` (not an undefined var); a readable secondary in light mode.

## [0.6.0-beta] — 2026-07-12

The first **vendored release** — this tag ships a pre-resolved `vendor/` ZIP (attached by CI) that
the no-shell core self-updater swaps in atomically.

### Added
- **API discovery (OpenAPI/Swagger).** `Tiger_OpenApi_Generator` reflects `@api` services + Forms into
  an OpenAPI 3 doc; `GET /api/openapi` serves it opt-in (`tiger.api.discovery`). `/api` is verb-agnostic.
- **Stateless `/api` auth.** `Authorization: Bearer tgr_…` (a `personal_access_token` `user_credential`
  factor) authenticates statelessly — same ACL + services, no session/cookie; CSRF skipped in token
  mode. `Tiger_Service_Token` mints/lists/revokes.
- **Module dependency provisioning.** `Tiger_Vendor` (+ `Tiger_Vendor_Environment`) installs a module's
  third-party PHP libs on no-Composer hosts (Composer / pre-resolved bundle / raw tarball → a shared
  `vendor-libs/` autoloading store, one-version-enforced) and front-end `asset` deps into a module.
  See [DEPENDENCIES.md](DEPENDENCIES.md).
- **One-click Updates screen** (`/system/updates`, superadmin) — TigerCore + modules, checkboxes,
  Update All, live step log. Modules self-install (no shell); **core self-update** via an atomic
  `vendor/` swap (`Tiger_Update_Core`: download → verify → swap → health-check → rollback), fed by a
  CI-built vendored release ZIP.
- **ACL Simulator** (`/system/acl`, superadmin) — `Tiger_Acl_Acl::explain()` answers "why am I locked
  out?" with the deciding rule (inheritance-aware) or deny-by-default. See [ACL.md](ACL.md).
- Homepage performance + API marketing sections; `MANIFESTO.md`, `ACL.md`, `DEPENDENCIES.md`.

## [0.5.1-beta] — 2026-07-10

### Security
- **Login responses no longer echo the identity object.** `AuthController::loginAction` /
  `otpAction` returned the full identity (`user_id`, `org_id`, `email`, `username`, `org_name`,
  `role`) in the success JSON, but the client only ever uses `redirect`. Trimmed to
  `{result, redirect}` (+ `twofa`) — least-disclosure: no user/org IDs, email, or role in a
  response body that lands in logs, browser history, and analytics captures. The session identity
  itself is unchanged (`_buildIdentity` still carries what the app needs server-side); a client that
  genuinely wants the current user calls the dedicated whoami, **`GET /auth/me`**.

## [0.5.0-beta] — 2026-07-10

### Changed
- **Docblocks standardized across the whole framework** — every `@api` class and public method now
  carries the reference contract (`@api`/`@internal` on classes; `@param`/`@return`/`@throws` on
  public methods) per the new **"Docblocks — the reference contract"** section in `AGENTS.md`. This
  is the machine-readable source the TigerDocs **reference generator** reads to produce API-reference
  pages, so the docblocks are now a first-class deliverable, not just comments. ~127 files touched
  across `Tiger_*` (library) and `core/` + first-party modules; comment-only, no behavior change.

## [0.4.0-beta] — 2026-07-10

### Added
- **`Tiger_Admin_Nav` — a top-level admin-sidebar nav registry.** Sibling of `Tiger_Admin_Settings`:
  where that adds a page under the **Settings** submenu, this lets a module add a **top-level** item
  to the admin sidebar from its Bootstrap — no core edit, ACL- and activation-gated for free. The
  PUMA `admin-menu` partial merges registered items in ahead of Settings. (First consumer: TigerDocs'
  in-admin **Help** center.)

## [0.3.0-beta] — 2026-07-10

### Added
- **`custom.css` — a theme override playground.** PUMA now ships `themes/puma/assets/custom.css`,
  loaded **last** in every layout (public / auth / admin), after `default.css` + skin + the
  layout-specific CSS — so anything in it wins the cascade. It's the safe place to try style
  overrides before deciding whether they belong in the theme proper; empty it to return to stock.
  Ships with an **experimental GitHub / Primer–flavored** pass on the auth cards (sign-in / lock /
  sign-up) and the docs prose — spacing, padding, margins, underlined H1/H2 — using `--bs-*` vars
  so it stays light/dark + skin aware.

## [0.2.1-beta] — 2026-07-09

### Fixed
- **Dropdown menus could render transparent.** Bootstrap derives `.dropdown-menu` background from
  `--bs-body-bg` with no fallback, so if that variable failed to resolve in a given context the
  menu went `transparent` and page content showed through it (visible on the header theme/lang/skin
  switchers over dark backgrounds). PUMA now sets an explicit, theme-aware opaque background with a
  concrete light/dark fallback.

## [0.2.0-beta] — 2026-07-09

### Added
- **Header regions in the PUMA public header.** The navbar now exposes two named regions a
  feature can fill without editing the theme (via `Zend_View` placeholders — the two-step view
  renders the action script before the layout, so a view script can contribute header chrome):
  - `tigerHeaderSearch` — a **centered search slot**. Empty by default (collapses to nothing);
    TigerDocs fills it with its ⌘K search launcher. Any feature can drop a search trigger here.
  - `tigerHeaderAuth` — the **sign-up / sign-in (or Dashboard) slot**. The theme renders a
    sensible default; a feature may override the whole region.
- **Restructured public navbar** to `[ brand ] [ menu ] [ · search · ] [ switchers ] [ sign up ]
  [ sign in ]`, and added a **Sign up** CTA (→ `/signup`) plus a first-party **Docs** link
  (→ `/docs`).

### Note
- **Versioning during beta.** This is the first **minor** bump (a feature — the header regions).
  Because `^0.1.0-beta` won't accept `0.2.x` (Composer's `0.x` caret locks the minor), the
  skeleton's constraint moves to a beta-line range (`>=0.1.0-beta <1.0.0`) so `composer update`
  keeps working across beta minors — see `webtigers/tiger`.

## [0.1.0-beta.3] — 2026-07-09

### Fixed
- **Language switcher respects `/xx/` URL prefixes.** On a locale-prefixed URL (e.g. `/en/docs`),
  the header switcher (`tiger.prefs.js`) now rewrites the prefix and navigates (`/en/docs` →
  `/es/docs`) — a `/xx/` prefix outranks the cookie (and resets it to match), so setting the cookie
  alone was silently overridden. On non-prefixed URLs it still just sets the cookie and reloads.

## [0.1.0-beta.2] — 2026-07-09

### Added
- **Module assets + deps in the scaffold + lifecycle.** `make:module` now emits optional
  `layouts/`, `assets/`, and `configs/dependency.ini` stubs. On **activate**, a module's `assets/`
  (if present) is symlinked into `public/_modules/<slug>` (`Tiger_Module_Installer::publishAssets`);
  **deactivate** removes it (`unpublishAssets`). **`Tiger_Module_Dependency`** adds lazy, no-boot-cost
  dependency *alerts* (never blocks): activate warns of required modules that aren't active
  (`missing()`), deactivate surfaces modules that depend on this one (`dependents()`) — both read
  `configs/dependency.ini` on demand. Wired into `bin/tiger module:activate|deactivate` and
  `System_Service_Modules`.
- **`bin/tiger link:assets`** + **`Tiger_Install::linkPublicAssets()`** — (re)create the webroot's
  `_theme`/`_tiger` symlinks with absolute targets computed from the app root. The failsafe way to
  wire assets on any host (recreate links, never copy); `--webroot` handles the cPanel split layout
  (`~/public_html` above the app). Idempotent; refuses to clobber a real directory. Reusable by the
  web installer.

## [0.1.0-beta.1] — 2026-07-09

First public **beta** of the Tiger platform Core — the kernel + multi-tenant substrate on
TigerZF (ZF1 for PHP 8.1–8.5). Beta: functional and running, API not yet frozen.

### Platform
- **Entry & bootstrap** — `Tiger_Application` front door (proxy/ALB normalization, path
  constants, `custom.php` hook, guarded dispatch) + `Tiger_Application_Bootstrap` base the app
  extends. Four-tier config cascade (`core.ini` ← `application.ini` ← `local.ini` ← DB).
- **Multi-tenant substrate** — `org` / `user` / `org_user` (membership = tenancy boundary +
  role carrier); auth split into `user_credential` (durable factors) + `auth_challenge`
  (one-time proofs).
- **Webservices (`/api`)** — the TIGER message pattern: one endpoint, routing in the message,
  `Tiger_Ajax_ServiceFactory` → `Module_Service_*`; standard response envelope; DataTables
  server-side processing built in.
- **Authorization** — `Tiger_Acl_Acl`, deny-by-default, role-on-membership, `acl.ini` + DB.
- **Auth & session** — `Tiger_Service_Authentication` (email-or-username identity resolution),
  DB-backed sessions with role-tiered idle TTL, server-authoritative auto-logout.

### Features
- **CMS** — `Tiger_Model_Page` content store (org-scoped, versioned, redirects) +
  `Tiger_Cms_Renderer` (markdown / html / trusted phtml / GrapesJS builder), slug dispatch.
- **Theming** — PUMA (vendored Bootstrap 5, zero-build) + swappable CSS-variable skins;
  theme-as-a-path with core-view fallback.
- **Routing overrides** — `Tiger_Routing_Overrides` + `Tiger_Controller_Plugin_RouteOverride`:
  modules declare pretty public routes (config-tier, admin-overridable) applied at the PHP
  layer — no web-server config (see ROUTING.md).
- **Admin framework** — `Tiger_Controller_Admin_Action` base + the `Tiger_Admin_Settings`
  registry; the house admin-screen template (see ADMIN.md).
- **UI/UX primitives** — vanilla, zero-dep: TigerButton, TigerDOM (reveals + notify/toast),
  TigerValidate (convenience validation), password strength, address autocomplete.
- **Forms** — `Tiger_Form` (array schema, ViewHelper-only, CSRF); convenience validation runs
  the same validators on blur and at submit; `Signup_Form_Signup` is the reference form.
- **Media** — storage-adapter pattern (local / S3, GCS/Azure planned) + scanner hooks.
- **Location** — `Tiger_Location` facade + capability-based adapters (Nominatim / AWS / IpApi);
  `Tiger_I18n_Country` (biased, localized ISO-3166 list).
- **i18n** — owner-prefixed translation keys, language-only locales, DB override tier.

### Tooling
- **`bin/tiger` CLI** — `migrate` / `migrate:status` / `migrate:rollback`, `install:admin` /
  `install:secrets` / `install:storage`, `make:module`, `crypto:rekey`, and the module manager
  (`module:install` / `activate` / `deactivate` / `remove` / `list`).

### Docs
- ARCHITECTURE, FEATURES, WEBSERVICES, ROUTING, ADMIN, AGENTS — the platform documents itself
  in-repo for AI + human contributors.

### Licensing
- **BSD-3-Clause**; Tiger™/WebTigers™ trademarks reserved (see LICENSE / TRADEMARKS.md).

[0.5.1-beta]: https://github.com/WebTigers/TigerCore/releases/tag/v0.5.1-beta
[0.5.0-beta]: https://github.com/WebTigers/TigerCore/releases/tag/v0.5.0-beta
[0.4.0-beta]: https://github.com/WebTigers/TigerCore/releases/tag/v0.4.0-beta
[0.3.0-beta]: https://github.com/WebTigers/TigerCore/releases/tag/v0.3.0-beta
[0.2.1-beta]: https://github.com/WebTigers/TigerCore/releases/tag/v0.2.1-beta
[0.2.0-beta]: https://github.com/WebTigers/TigerCore/releases/tag/v0.2.0-beta
[0.1.0-beta.3]: https://github.com/WebTigers/TigerCore/releases/tag/v0.1.0-beta.3
[0.1.0-beta.2]: https://github.com/WebTigers/TigerCore/releases/tag/v0.1.0-beta.2
[0.1.0-beta.1]: https://github.com/WebTigers/TigerCore/releases/tag/v0.1.0-beta.1
