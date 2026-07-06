# Tiger — Features

A factual inventory of what the platform does. For the *why* behind these choices see
[ARCHITECTURE.md](ARCHITECTURE.md); for the `/api` model see [WEBSERVICES.md](WEBSERVICES.md).

Tiger is a multi-tenant SaaS foundation built on **TigerZF** (ZendFramework 1 modernized
for PHP 8.1–8.5). It ships as a framework (`webtigers/tiger-core`, consumed from `vendor/`)
plus a skeleton (`webtigers/tiger`, created once with `composer create-project`). Licensed
`(MIT AND BSD-3-Clause)`.

## Foundation

- **Zero build toolchain.** No npm/webpack/Sass. `composer install` + an asset symlink and
  the UI renders. Node is only needed if a theme opts into a build.
- **One bootstrap path for web and CLI.** `Tiger_Application::boot()` gives web requests and
  `bin/tiger` commands identical constants, config, and DB adapter.
- **Proxy/load-balancer aware.** Normalizes `X-Forwarded-For`/`-Proto` so HTTPS URLs, client
  IPs, and redirects are correct behind an ALB or reverse proxy.
- **App entry hook.** An optional `custom.php` runs early and survives framework updates.

## Front-end agnostic

Tiger imposes no front-end framework. The core ships none, and the same backend supports
whatever you want to build against it:

- **Server-rendered** `.phtml` + progressive JavaScript — the default, and what the PUMA theme
  uses (Bootstrap, no build step).
- **SPA** — React, Vue, Svelte, or anything else — talking to the JSON `/api` message endpoint.
- **Any client** — a mobile app, htmx, or a CLI — the `/api` contract is plain JSON.

Rendering is a *theme* concern, not a platform one: a theme can ship server-rendered views, a
built SPA bundle, or both. The `/api` message pattern is the single stable contract every
front-end consumes, so your choice of UI stack (or lack of one) is never dictated by the
framework.

## Multi-tenancy

- **Org / User / membership model.** `org` (self-referential `parent_org_id` for sub-tenants),
  a thin `user` identity, and `org_user` — the membership row that is *both* the tenancy
  boundary and the role carrier.
- **Role-on-membership.** A user can hold different roles in different orgs (admin in one,
  viewer in another). Absence of a membership row *is* the cross-tenant denial.

## Authentication

- **Identity separated from credentials.** `user` holds no password; `user_credential` is a
  one-to-many factor table (`password`, `sms`, `totp`, `webauthn`, `oauth`) — new factor
  types are new rows, not schema changes.
- **Password security.** Bcrypt; configurable policy (min length, history/reuse prevention,
  optional complexity/expiry — NIST 800-63B-informed defaults); brute-force lockout;
  constant-time verification (no user enumeration); password history retained on change.
- **One-time challenges.** `auth_challenge` backs OTP / password-reset / magic-link flows —
  hashed codes, single-use, TTL, attempt-limited.
- **Sessions.** Database-backed shared store (required behind a multi-instance load balancer),
  with tiered TTLs by privilege and a file-handler fallback for single-box/fresh installs.
- **Login audit log.** Append-only record of every sign-in attempt (success/failure/locked,
  IP, user agent) — the substrate for rate-limiting and anomaly detection.

## Authorization

- **Deny-by-default ACL** built on `Zend_Acl`, enforced by an unbypassable front-controller
  plugin (not a base-controller call you can forget to inherit).
- **Live roles.** The role is resolved fresh from `org_user` on every request, so a
  revoked or changed membership takes effect on the next request — no stale sessions,
  no forced re-login.
- **Data-driven policy.** A default role graph (guest → user → manager → supermanager →
  admin → superadmin → developer) and all rules live in `acl.ini` + the `acl_*` tables;
  the engine hardcodes nothing. Apps add/re-parent their own roles.

## Web services (`/api`)

- **Message pattern, not REST-by-URL.** A single `/api` endpoint; the target
  `module`+`service`+`method` (or `controller`+`action`) travels in the request body with the
  payload. The whole message is handed to the service; the caller reads one response object.
- **Service base + transaction helper.** `Tiger_Service_Service` routes the message to the
  named method and provides `_success`/`_error`/`_formErrors`, an ACL gate, and
  `_transaction()` (begin → work → commit, rollback + rethrow on any error).
- **Reserved-module guard.** Kernel namespaces (`tiger`, `zend`, `core`, …) are never
  dispatchable through `/api`.

## Data layer

- **UUID primary keys, client-generated.** v7 (time-ordered — index-local, sortable by
  creation) is the default; v4 (opaque) for tokens/secrets. Lowercase canonical form.
- **`Tiger_Model_Table` base.** Mints the UUID on insert, stamps `created_at`/`updated_at`
  and `created_by`/`updated_by` from the request actor, and supports soft-delete
  (`deleted` flag) with finders that exclude deleted rows by default.
- **Standard columns** on every domain table: `status`, `deleted`, `created_by`,
  `updated_by`, timestamps.
- **Migrations.** A dependency-free runner (`bin/tiger migrate` / `:status` / `:rollback`)
  that scans core, app, and per-module `migrations/` dirs; additive-only.

## Forms

- **`Tiger_Form`** — `Zend_Form` configured with array config (not `.ini`), decorators
  stripped to ViewHelper-only so the view owns all markup (forms are AJAX-submitted).
  Subclasses declare a declarative `elements()` schema; CSRF and a translate helper are
  baked in. Validate with `isValid()` / `isValidPartial()`.

## Configuration

- **Layered cascade, live overrides.** `core.ini` (framework) ← `application.ini` (app) ←
  `local.ini` (secrets/per-deploy) ← **the `config` table** at request time. DB rows override
  any setting with no deploy, scoped global or per-org.
- **Per-org theming is the same mechanism** — an org's `config` row for `tiger.skin` reskins
  that org.

## Internationalization

- **Semantic, owner-prefixed keys** (`core.*`, `app.*`, `<module>.*`) in human-readable PHP
  array files, cascading core → package modules → app modules → app global (last wins).
- **Language-only locales** (`en`, `es`) with region subtags reserved for genuine regional
  divergence.
- **Semantic locale URLs.** `/es/anything` works on every route (the prefix is resolved and
  stripped); resolution order is URL → cookie → browser → default, and the choice persists to
  a cookie (the header language switcher writes the same cookie + localStorage).
- **Live translation overrides.** The `translation` table overrides or adds any string at
  request time, no deploy — mirroring the config table. API response messages localize
  automatically.

## Theming & UI

- **PUMA theme** — vendored Bootstrap 5 (no build), Google Fonts, Font Awesome.
- **Skins** — CSS-variable overlays swappable at runtime; ships Bootswatch-derived palettes
  (Zephyr/Flatly/United) plus a custom one. A live **skin switcher** hot-swaps them with no
  reload; per-user (cookie) and per-org (config).
- **Light / dark** via Bootstrap's native `data-bs-theme`, with a browser/light/dark toggle
  and no-flash resolution.
- **Admin shell** — fixed header (search, notifications, language/theme/skin switchers, user
  menu), collapsible sidebar with ACL-aware nav, and an optional right aside.
- **Public site chrome** — a responsive header/nav + footer and a starter landing page ship in
  the PUMA theme, ready to customize (the switchers restyle the page live).
- **Theme vs skin split** — theme = structure/layout; skin = CSS-only look; both resolve
  per request and can vary per org.

## Logging

- **`Tiger_Log`** — a structured facade over `Zend_Log` emitting one JSON object per line,
  auto-enriched with a per-request id and the authenticated user/org/role, and it never
  throws into the caller.
- **Pluggable sinks** via config: `null`, `errorlog`, `stderr`/`stream`, `syslog`, or direct
  **AWS CloudWatch / GCP Cloud Logging / Azure Monitor** (cloud SDKs optional; missing → warn
  and fall back to `errorlog`). Config-driven minimum level.

## Console (`bin/tiger`)

- `migrate` / `migrate:status` / `migrate:rollback` — schema migrations.
- `install:admin` — create the founding org + owner (flags or prompts; `--username`, `--role`, …).
- `make:module` — scaffold a live module (controller + `/api` service + ACL + views + config).
- `version`.

## Error handling

- Styled **403 / 404 / 500** pages rendered in the active theme (light/dark aware); every 500
  is logged via `Tiger_Log`; a full debug bundle shows outside production. Unknown URLs 404
  cleanly (guests aren't bounced to login for a nonexistent page).

## Deployment

- **Works on any web server.** A zero-config `public/.htaccess` (front-controller routing) is
  the default; reference configs ship for an Apache vhost (the faster form), nginx, and
  Caddy/FrankenPHP.
- **Update-safe.** Framework code lives in `vendor/` and is replaced by `composer update`;
  everything you own lives outside it. Extend, don't edit.

## Extending the platform

- **Modules** (`application/modules/<name>/`) are self-contained and auto-discovered:
  controllers, `/api` services, models, migrations, views, ACL, routes, and language files.
- **App library** (`application/library/App/`) for shared, route-less code (base classes,
  helpers, integrations).
- **Four layers:** `Zend_*` (engine) / `Tiger_*` (platform) / `App_*` (app-shared) / modules
  (features).
