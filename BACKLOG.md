# Tiger — Backlog

A manual, lightweight tracker for planned features and known issues (in lieu of GitHub issues
for now).

**Workflow:** when an item ships, **delete it from this file**. If it's a user-facing
capability, add it to [FEATURES.md](FEATURES.md). Keep this list short and current — it's a
working to-do, not a changelog (git history is the changelog).

## Features (planned)

- **Core CMS module** *(building this week)* — a first-party module for DB-driven page content,
  so anyone can build a module that renders pages. The vision: a better-architected WordPress.
  - **✅ Data layer built** — `page` / `page_version` / `page_redirect` (migrations 0014-0016) +
    `Tiger_Model_Page` (org-cascade resolve + publish/schedule gate). Decisions locked: page|layout|
    partial in one table, `org_id` cascade ('' = global, tenant wins), formats html/markdown/phtml
    (phtml = trusted-only), versioning from day 1, future `published_at` = scheduled, theme-agnostic.
  - **✅ Renderer built** — `Tiger_Cms_Renderer` (html/markdown[Parsedown]/phtml[renderString] +
    `[shortcode]` registry + layout wrap). Needs `Zend_View` string rendering (done: TigerZF 1.32.0).
  - **✅ Page dispatcher built** — `PageDispatch` plugin (routeShutdown, non-greedy) + `PageController`
    (both layout modes) + `page_redirect` 301s. The CMS is end-to-end (insert a row, hit its URL).
  - **✅ Version-on-save built** — `Page::save()` snapshots to `page_version` (+ slug-change 301 redirect); `restoreVersion()` reverts. Wired for the admin UI to call.
  - **Admin UI** — author pages / layouts / partials (list, edit, publish, schedule, redirects).
  - **Package as the first site-theme module** (per the theme-as-module model).
  - **Security (the WordPress footgun)** — DB templates are code. Restrict authoring to trusted
    admins and/or use a safe, limited template syntax (never raw `eval` of arbitrary PHP). Design
    this in from the start, not after.
  - **Extensible** — modules register renderable content/types, so the CMS is a rendering
    *substrate*, not a monolith.

- **Billing module — installable, Stripe-only** — a reusable **app-level module** (NOT core;
  billing is a module like Account) that interfaces **Stripe exclusively** (deliberate: no
  multi-processor abstraction — "no one uses the others"). Scope (TBD, but roughly): a Stripe
  customer per **org** (ties to the tenant substrate), plans/prices, subscriptions, Checkout +
  Customer Portal, and a webhook endpoint (Stripe events → local state). Declares its own
  `stripe/stripe-php` dependency. Underpins the hosted/marketplace/SaaS business paths.
- **Marketplace module — app-level, PRIVATE (WebTigers-only)** — the **catalog / storefront**: a
  listing of installable plugins/modules (+ themes) browsable from within Tiger that feeds the core
  **Module Manager** (below) for auto-install. Works **with the Billing module** (paid items settle
  via Stripe). Private/internal (only our apps run the storefront); what it lists installs anywhere.
  Curated — we vet everything listed.

- **Core Module Manager — WordPress-style plugin lifecycle (CORE, Tiger-owned)** — find, install,
  and manage marketplace modules from inside Tiger:
  - **Find** (browse the Marketplace) → **Install** (download + verify + unzip the package into the
    managed-modules dir) → **Activate** (run the module's IDEMPOTENT setup — its own migrations + an
    `activate` hook) → **Deactivate** (teardown/disable; uninstall removes the files).
  - **Module-aware = the safety guarantee:** tracks ONLY managed (marketplace-installed) modules in
    a registry (a `module` table + per-module manifest) and **never touches developer-authored
    custom modules** — the same ownership boundary as `vendor/` (managed vs custom = Tiger-owned vs
    app-owned).
  - **Security = the WordPress supply-chain footgun; design in from day 1:** curated + signed
    packages from the trusted WebTigers marketplace ONLY, checksum verification, install gated to
    superadmin/developer, explicit "activate runs code" trust boundary. Do NOT copy WP's
    install-anything-from-anywhere model.
  - Ties into the migrator (run a module's OWN migrations on activate) and `bin/tiger`
    (`module:install|activate|deactivate|list`).

- **SMS / OTP flow** — storage is built (`auth_challenge` + the `user_credential` `sms` factor);
  needs the send + verify actions wired.
- **User prefs service** (`core/user/setprefs`) — `tiger.prefs.js` posts theme/skin/lang
  choices to this endpoint best-effort; build it to persist per-user prefs server-side.
- **Per-org theming UI** — the resolver already works (an org `config` row for
  `tiger.skin`/`tiger.theme`); needs an admin screen to set it.
- **Per-org translation overrides** — the `translation` table already supports `scope=org`;
  needs the request-time per-org layer + an admin screen.
- **Sign-in history UI** — surface the append-only `login` audit log to users/admins.
- **create-project post-install hook** — auto-symlink core assets (`_tiger`, `_theme`) on
  `composer create-project` so a fresh app renders with zero manual steps.

## Issues / tech debt

- **Error pages not i18n-keyed** — `core/views/scripts/error/error.phtml` uses literal English;
  key it to `core.error.*` (the keys are already seeded in `core/languages/`).
- **Packagist webhooks** — only TigerZF has its per-repo Packagist auto-update hook; add one
  for TigerCore + Tiger before their first tagged release (or install the org-wide Packagist
  GitHub App).
- **`Zend_Version` secondary constant** — the "latest stable available" constant is still on an
  old value; align it in a TigerZF patch.

## Later / maybe

- **Redis session handler** — a swap-in alternative to the DB session handler for scale.
- **Bootswatch full-look skins** — current skins are CSS-variable overlays; a per-skin full
  base-swap if a pixel-perfect Bootswatch theme is ever wanted.
- **Validator message translation** — `Zend_Validate::setDefaultTranslator` so validator
  messages localize (optional).
