# Changelog

All notable changes to **Tiger Core** (`webtigers/tiger-core`). Format follows
[Keep a Changelog](https://keepachangelog.com/); this project uses [SemVer](https://semver.org/)
— while `0.x`, the public API (`@api`) may still shift between minor versions.

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
