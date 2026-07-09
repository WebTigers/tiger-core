# Changelog

All notable changes to **Tiger Core** (`webtigers/tiger-core`). Format follows
[Keep a Changelog](https://keepachangelog.com/); this project uses [SemVer](https://semver.org/)
— while `0.x`, the public API (`@api`) may still shift between minor versions.

## [Unreleased]

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

[0.1.0-beta.1]: https://github.com/WebTigers/TigerCore/releases/tag/v0.1.0-beta.1
