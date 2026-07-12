# Tiger — Backlog

A manual, lightweight tracker for planned features and known issues (in lieu of GitHub issues
for now).

**Workflow:** when an item ships, **delete it from this file**. If it's a user-facing
capability, add it to [FEATURES.md](FEATURES.md). Keep this list short and current — it's a
working to-do, not a changelog (git history is the changelog).

## Priorities (next up)

1. **One-click "Updates" admin screen** — **✅ v1 built.** *Login → Admin → Updates* (top-level nav,
   superadmin): one screen lists everything stale — **TigerCore + every installer-managed module** —
   with checkboxes + **Update / Update All**, and a **live step log**. `Tiger_Update_Checker` diffs
   installed-vs-latest (core via Packagist, modules via their GitHub latest release; cached);
   `System_Service_Updates` applies + logs each step (also to `Tiger_Log`). **Modules self-install the
   real no-shell way** (`Tiger_Module_Installer`). **Core self-update is now built too** —
   `Tiger_Update_Core` does the no-shell **atomic `vendor/` swap** (download → sha256-verify →
   zip-slip-guarded extract → rename-swap → health-check → **rollback** on failure), gated by a
   `var/update/.maintenance` 503 flash (auto-expires 120s); CI (`bin/build-release-zip.sh` +
   `release-zip.yml`) attaches the **pre-resolved vendored ZIP** to each release, and the service
   swaps it in the moment one's published (advisory fallback until then). Swap + rollback + checksum
   guard unit-tested. *Remaining:* **publish the first vendored release ZIP** (cut a release → CI
   attaches it → core one-click goes live); a **persisted update-history** table (steps → `Tiger_Log`
   + the run response today); CI asserting `Tiger_Version::VERSION` == the git tag.
2. **API discovery — OpenAPI / Swagger for `/api`** *(homepage "Discoverable by design" claim).*
   **✅ Largely shipped.** `Tiger_OpenApi_Generator` reflects services + Forms → an OpenAPI 3 doc
   ([WEBSERVICES.md](WEBSERVICES.md) §9); `GET /api/openapi` serves it opt-in (`tiger.api.discovery`);
   and the **TigerAPIDocs** module (`WebTigers/TigerAPIDocs`) renders **Swagger UI** at `/apidocs`
   (JSON dump when the Swagger lib is absent). *Remaining:* role-filtered discovery (Phase 3), richer
   `data` typing, and services adopting `@apiRequest <Form>` for form-derived request schemas.
3. **Stateless `/api` auth (token mode)** — **✅ built.** `Authorization: Bearer tgr_…` (a
   `personal_access_token` `user_credential` factor, hashed at rest) → the gateway resolves the
   identity and runs the **same ACL + same services**, auto-detected: token → **stateless** (no
   session cookie/row — request-only `Zend_Auth_Storage_NonPersistent`, session start skipped in the
   bootstrap), else session → stateful; an *invalid* token stays guest (no session fallback). **CSRF
   is skipped in token mode** (`Tiger_Form`); mint/list/revoke via `Tiger_Service_Token`. Verified on
   dev (Bearer auth works, no `PHPSESSID` emitted). *Remaining:* a token-management admin screen; the
   token carrying an explicit **org/map** context (feeds #4); scoping (read-only / per-service).
4. **App-level ACL — floor + maps + token-selected context** *(design of record: [ACL.md](ACL.md)).*
   **Phase 1 (debuggability-first) built:** `Tiger_Acl_Acl::explain($role, $resource, $privilege)` —
   the decision **plus** the deciding rule (explicit allow/deny, inheritance-aware) or deny-by-default,
   and the role chain — surfaced as an admin **ACL Simulator** (`/system/acl`, superadmin) so "why am I
   locked out?" is always answerable. Verified on the live ACL (inherited allows traced, deny-by-default
   spine identified, unknown roles caught). *Staged (phase 2 — the access-changing parts, built
   carefully):* the **named policy maps** (`acl_map` + `map_id` storage), floor+map **composition**
   (floor immovable, deny-wins), **token→map** selection (the token from #3 carries a map/org context),
   the **narrows-never-widens** enforcement, and per-tenant map authoring. Full model in ACL.md.
5. **Module dependency provisioning — libs on no-Composer hosts** *(design of record:
   [DEPENDENCIES.md](DEPENDENCIES.md)).* **Foundation built.** `Tiger_Vendor_Environment` (fail-closed
   capability detection), the shared `vendor-libs/` store + bootstrap autoloading
   (`_initVendorLibraries`), and `Tiger_Vendor` (Tier 1 Composer exec · Tier 2 pre-built bundle · Tier
   3 raw tarball → download+verify+unpack+autoloader-generate) are in tiger-core; the installer
   provisions `module.json` `dependencies.php` and surfaces per-dep statuses. Verified end-to-end (a
   real GitHub lib installs into the store and autoloads). *The thesis:* never solve a dep graph on a
   shared host — consume **pre-resolved bundles** built off-box.
   *Staged (WebTigers infra):* the **Vendor Library Registry** repo + CI bundle-builder + published
   AWS/Stripe/Guzzle bundles (the provisioner *consumes* these). *Follow-on:* `asset`-dependency
   provisioning + rewire TigerAPIDocs' Swagger UI onto it; Billing's Stripe SDK as the first real
   Tier-2 consumer; conflict reporting for the one-version rule; the skeleton `.gitignore` adds
   `vendor-libs/`; CLI-auto / web-advise polish.

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
  - **✅ Admin UI built** — the first-party `cms` module (`modules/cms`): a DataTables content
    list + a page editor (create/edit, publish/schedule, format, layout, slug/key), **version
    history + restore**, and soft-delete — all through `Cms_Service_Page` (validate →
    `Tiger_Model_Page::save`). Renders in the PUMA admin shell; ACL-gated "Content" sidebar link.
    Latest **DataTables + jQuery** vendored for the admin's data grids (admin-only; the public
    theme stays vanilla). *Next polish:* richer body editor (plain textarea today), a per-org
    scope picker (editor writes global `''` for now), and i18n of the admin labels.
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
- **Module Installer + Vendor Registry — WordPress-familiar, open-but-reviewable (CORE, Tiger-owned)**
  — the evolution of the old "private curated marketplace": an **open community registry** + install
  from any **public GitHub repo URL**. Public code is the price of admission — **no private repos**
  (module code must be out in the open for review). Vendors may sell pro tiers; we **encourage a
  free tier for every module**. First end-to-end test: the **`docs` module** (WebTigers/tiger-docs).

  - **STATUS (2026-07-11): the core is BUILT.** `Tiger_Module_Installer` (tarball install/update),
    `Tiger_Module_Github`, `Tiger_Module_Registry` (fetch/cache `WebTigers/Vendors/index.json`), the
    `module` table, the CLI (`module:install|remove|list|activate|deactivate`), and the **Module
    Manager admin screen** (`system` module: installed list + Add New/registry search + Install-from-URL
    + per-module "Update to vX") all ship. Remaining = proactive update badges + the unified Updates
    screen + core self-update + logs (see the **Update system** item below). The bullets here are the
    design of record; treat the shipped pieces as done.

  - **Discovery — the Vendor Registry (`WebTigers/Vendors` repo).** The registry IS a git repo (no
    server to run/scale). A vendor opens a PR adding their listing; our **AI reviews open PRs a few
    times/day** — manifest schema valid, repo public + exists, license present, the claimed release
    tag exists, module layout conforms, basic safety heuristics — then opens a **response PR on the
    vendor's repo** with findings. Accepted → merged into the registry; rejected → resubmit.
    - **Recommendation: one JSON file per module** (`/data/<slug>.json`), *not* one giant file — PRs
      never collide, and a CI step compiles `/data/index.json` (the search index). A single "massive
      JSON" would turn every submission into a merge conflict.
    - Tiger polls `index.json` a few times/day (or on-demand when the admin opens *Add Module*),
      caches it locally (TTL) → search is local + instant, no GitHub hammering.

  - **Package format (`module.json`).** The repo **root IS the module** (WP-style); on install its
    contents become `application/modules/<slug>/`. Manifest = slug, version, description, author,
    homepage, repository, license, `requires` (tiger/php), `provides` (routes/acl/migrations/assets),
    `pricing` (free|freemium|paid + `pro_url`). The registry listing = this manifest + the **vetted
    release ref**. *(Format defined by `tiger-docs/module.json`.)*

  - **Install mechanism (no git/composer on the host).** Download the GitHub release tarball for a
    **pinned tag** (`/archive/refs/tags/<tag>.tar.gz`), **verify its SHA** against the registry's
    vetted ref (what was reviewed == what installs), **zip-slip-guard** the extraction into
    `application/modules/<slug>/`, run the module's migrations, publish `assets/` →
    `public/_modules/<slug>/`. Same pipeline for **Install from URL** (paste a public repo URL for
    unlisted/dev — resolves the latest release).

  - **Lifecycle + the active-modules boundary.** A new **`installed_module`** table (slug, name,
    version, repo, ref/SHA, source [registry|url], active, timestamps) is the source of truth.
    **Module discovery must consult it:** today ZF1 auto-loads *all* of `application/modules`; to get
    WP-style activate/deactivate (installed-but-off), discovery loads only **active managed** modules
    — developer-authored *custom* modules stay always-on (managed vs custom = Tiger-owned vs app-owned,
    same boundary as `vendor/`). `bin/tiger module:install|activate|deactivate|update|list`. Activate
    runs the module's migrations + an idempotent `activate` hook.

  - **Trust model — be honest (the WP supply-chain footgun).** Installing a module runs its PHP in
    your app = RCE by design; there is no PHP sandbox. Guardrails: **public-repo-only** (auditable +
    accountable), **pinned refs** (install exactly what was reviewed, never a moving HEAD), checksum
    verify, install gated to `code.execute`/superadmin, an explicit "activate runs code" confirm.
    **A listing means triaged, not certified safe** — surface tiers: *community* (listed) / *verified*
    (deeper review) / *partner*. Same ethos as **Tiger Code** (code that runs must be reviewable) —
    lean into it, don't pretend it's risk-free.

  - **WP-familiar UX.** A **Modules** admin screen (≈ Plugins): installed list with activate /
    deactivate / delete + **update badges** ("v1.2 available"); **Add New** = registry search (cards:
    name, author, description, **free-tier badge**, Install) + **Install from URL**; a details view
    (changelog, requires, license, screenshots from the manifest). Free tier **encouraged** via a
    badge + sort preference, never enforced.

  - **Optional paid layer (Billing).** A curated/paid **storefront** (WebTigers-run) can sit *on top*
    of the open registry — paid modules settle via the **Billing module** (Stripe), license keys gate
    the pro tier. The open registry stays the free base; commerce is an additive layer, not a gate.

- **Extension model — how modules extend Tiger (the anti-WP-hooks design; decide before the admin UI).**
  NOT WP's ~2,000 stringly-typed hooks (WP needs them because it's procedural). Four typed mechanisms
  + lifecycle:
  1. **ADD** (most plugins) — just *be* a module (auto-discovery; zero registration).
  2. **REGISTER** — typed registries per surface (have: `Tiger_Cms_Renderer::registerShortcode`;
     later: admin nav items, dashboard widgets, settings panels).
  3. **REACT** — one small **`Tiger_Event`** facade over ZF1's **`Zend_EventManager`** (already in
     TigerZF — don't build a bus from scratch): `on($e,$cb,$pri=1)`→`attach`, `emit($e,$target,$params)`→
     `trigger` (action), `filter($e,$value,$ctx)`→`trigger` + `ResponseCollection::last()` (value
     transform, used sparingly). Semantic, **namespaced**, **declared** events (~30–50 core, *ever*),
     documented like ACL resources / translation keys; modules fire their own (`billing.*`).
     Declarative subscriptions (module config) are `attach`ed at bootstrap.
  4. **MODIFY** — service polymorphism (`App_Service_Base extends Tiger_Service_Service`), not filters.
  Lifecycle = the Module Manager registry. **KEY WIN: subscriptions are DECLARATIVE** (in module
  config, like `acl.ini`/`routes.ini`) → the Module Manager/Marketplace shows exactly what a module
  hooks/routes/requests BEFORE install = inspectable + auditable (WP can't).
  **Seed core events (first dozen)** — all *actions* (notify + side-effect); rare value-transforms go
  in a tiny separate `filter:` set (`filter: page.body`, `filter: mail.message`):

  | Event | Fired when / by | Payload | Subscribers (examples) |
  |---|---|---|---|
  | `user.created` | new identity (Tiger_Install / signup) | user_id, email | welcome email, provisioning |
  | `auth.login` | sign-in succeeds (Tiger_Service_Authentication) | user_id, org_id, ip | last-seen, audit |
  | `auth.login_failed` | a sign-in fails | identifier, ip, reason | brute-force/rate-limit, alerting |
  | `auth.locked` | account locked after N failures | user_id, until | security alert |
  | `password.changed` | password credential (re)set | user_id | notify, invalidate other sessions |
  | `org.created` | new tenant/org | org_id, created_by | **provision Stripe customer**, seed defaults |
  | `org.member_added` | user joins an org (org_user) | org_id, user_id, role | onboarding, **seat billing** |
  | `org.member_role_changed` | membership role change | org_id, user_id, from, to | access review, audit |
  | `page.saved` | CMS page saved (Page::save) | page_id, status, version | search reindex, cache bust |
  | `page.published` | page goes live | page_id, slug, locale | sitemap, notifications |
  | `module.activated` | Module Manager activate | module, version | cross-module wiring |
  | `module.deactivated` | Module Manager deactivate | module | cleanup |

- **WHM/cPanel one-click installer — the WordPress distribution play** — a WHM/cPanel plugin
  (à la Softaculous / Installatron) that lets a **webhost** offer Tiger as a near-one-button
  install for their customers. It automates the whole skeleton bring-up: `composer
  create-project` the `webtigers/tiger` skeleton → `composer install` → provision a DB + user →
  write `local.ini` → run migrations → `install:admin`. Whether the customer then runs Tiger as
  a **standalone CMS** or as their **startup's MVP framework** is irrelevant to the installer —
  the install is identical; the difference is only what they build on it afterward.
  - **Why it matters:** WordPress's ubiquity came largely from being the default one-click
    install on every cPanel host. Same channel, better architecture — a webhost checkbox is a
    growth lever that framework quality alone won't buy. A natural distribution arm alongside the
    hosted/marketplace business paths.
  - **Scope (TBD):** a cPanel/WHM plugin package + a fully **unattended** install path
    (flags-only, no prompts — `bin/tiger install:admin`/`migrate` already support this), sane
    defaults, the asset-symlink step (see the create-project hook below), and a post-install
    landing that hands the new admin their first-run credentials.
  - **Architecture (as we build the first-run installer):** ONE **install service/engine** (the
    real work) with TWO thin front-ends — the `bin/tiger` CLI (AI/dev path) and a first-run **web
    wizard** (cPanel path). First-run detection + an `installed` sentinel that permanently disables
    the wizard once set. Writes DB creds → `local.ini` (must be a file, pre-DB, gitignored); site
    name/domain/settings → the `config` table (live-override, per [[config-discipline]]); install
    path auto-derived (`APPLICATION_ROOT` = `public`'s parent). Requirements pre-flight (see below)
    + a manual-paste fallback when `local.ini` isn't writable.
  - **Requirements pre-flight page (source of truth: [`INSTALL.md`](INSTALL.md)).** The wizard's
    FIRST screen reads the live environment — `phpversion()`, `ini_get()` for each directive
    (`memory_limit`, `max_execution_time`, `max_input_time`, `max_input_vars`, `post_max_size`,
    `upload_max_filesize`), `extension_loaded()` for the required set (`pdo_mysql`, `mbstring`,
    `openssl`, `sodium`, `gd`, `curl`, `zip`, `tokenizer`, `fileinfo`), a DB-connection test, and a
    write test on `var/` + `local.ini` — and renders **pass / warning / fail** per item with the
    exact target value and *where to change it in cPanel* (MultiPHP INI Editor / Select PHP
    Version). A user fixes their `php.ini` / cPanel **before** install, so no one ends up with a
    half-finished install that dies on the first upload, migration, or module build. Fails block;
    warnings advise. Generate the checklist FROM `INSTALL.md` so the doc and the check never drift.
    Same pre-flight belongs on **module install/update** (a module's `requires` + the reference
    build's needs, e.g. execution time) and should re-run **per server** in a fleet.
  - **DECIDED (2026-07-07): NO table prefix.** It only ever bought shared-DB coexistence + weak
    obscurity (never real security). Tiger covers both needs better: **multi-tenancy** (`org_id`)
    for many sites in one install, and a **separate DB schema** per genuinely-separate install
    (just a different DB name in `local.ini`). A prefix would also leak/bypass through our raw
    `Zend_Db_Select` service queries. Models + migrations keep literal table names.

- **Update system — a WordPress-simple, one-click "Updates" admin screen** (companion to the
  Module Installer + WHM/cPanel entries above; current mechanics documented in [`UPDATING.md`](UPDATING.md)).
  - **The UX is the point — dead simple, exactly like WP's *Dashboard → Updates*.** *Login → Admin →
    Updates.* ONE screen lists everything with a pending update — **Tiger + TigerCore to latest**, and
    **each module** — with checkboxes + an **Update** / **Update All** button. Click and it
    **self-installs**: download → verify → apply → migrate → warm → done. **No shell, no Composer, no
    file juggling, no FTP.** A progress line + a rollback on failure; a maintenance flash during a core
    swap. Everything below is just the engine that makes that one click real — from the user's side
    it's a checkbox and a button.
  - **Full audit log of every run — "what broke" must be answerable.** Log EVERY step (resolve ref →
    download → checksum/signature verify → extract → run each migration → `vendor/` swap → asset
    republish → cache warm) through `Tiger_Log` (structured JSON), AND persist a per-update record
    (before/after versions, repo + ref/SHA, the exact migrations run, timings, and outcome =
    success | rolled-back + the error). **Stream it live to the UI** during the run and keep it
    **viewable afterward** (an "Update history / log" panel). This matters most for the core swap +
    migrations, where a half-applied update needs a precise trail to diagnose and to drive the
    rollback. No silent steps — a failure names itself, its input, and the rollback taken.
  - **Already built** (don't re-scope): `composer update` for core/platform; and for modules the full
    engine + UI — `Tiger_Module_Installer` (tarball → migrate → publish → record in the `module`
    table), the **Vendor Registry** (`Tiger_Module_Registry` fetch/cache of `WebTigers/Vendors/
    index.json`), a **Module Manager admin screen** (`system` module: installed list + Add New /
    registry search + Install-from-URL), **per-module update via the UI** ("Update to vX" = forced
    re-install), and `bin/tiger module:install|remove|list|activate|deactivate`. The gaps:
  - **Version-change DETECTION on the installed list.** The registry is already fetched/cached
    (`Tiger_Module_Registry`) and the `module` table stores installed `version`/`ref`, but nothing
    **diffs installed-vs-latest** — so the installed list shows no "update available" badges (you
    only see an update inside *Add New*). Add the diff (`version_compare` installed vs registry
    latest) → badges on the installed list; and a **core check** (Packagist `repo.packagist.org/p2/
    webtigers/tiger-core.json` / GitHub tags vs `Tiger_Version::VERSION`) — core has no detection at all.
    - **Fix the version source of truth:** `Tiger_Version::VERSION` is a hand-maintained constant —
      assert it equals the git tag in CI (or derive it) so detection can't silently lie.
  - **`module:update <slug>`** as a first-class verb (today CLI "update" = re-`install` at a newer
    ref; UI update is the "Update to vX" button) — resolve the target ref, re-run the installer,
    re-migrate, re-publish, update the row; keep the old files until the health-check passes (rollback).
  - **The unified one-click *Updates* screen** — the Modules admin screen already EXISTS and updates
    modules one at a time (via Add New); what's missing is the WP *Dashboard → Updates* view:
    everything stale (core + every module) in one place, checkboxes, **Update All** (the Priority above).
  - **No-shell / cPanel path (the biggest lift).** Modules are ~there: `installFromTarball()` is pure
    PHP (curl download, `PharData` extract, `Tiger_Db_Migrator`, symlink publish) and modules carry
    no Composer deps — wrap it in a **web service/controller**; close the cPanel caveats (tarball
    temp in `var/` not system tmp; a **copy fallback** where symlink is unavailable; `phar`+`zlib`
    +`max_execution_time` in the [`INSTALL.md`](INSTALL.md) pre-flight). **Core** is the hard one:
    ship a **pre-built vendored release ZIP** (CI-resolved `vendor/`, so no dependency resolution on
    the host) + a browser updater that: pre-flights → downloads → **verifies checksum/signature** →
    extracts to a staging dir (zip-slip guarded) → **atomically swaps `vendor/`** (`vendor.new` →
    rename `vendor`→`vendor.old`, `vendor.new`→`vendor`, `opcache_reset()`) under a maintenance flag
    → migrates → health-checks → drops `vendor.old` (else rolls back by un-renaming). Only `vendor/`
    moves; app-owned files are never touched (the ownership boundary is what makes a hot core swap
    survivable). Primitives already exist and are Composer-free — this is orchestration + the atomic
    swap + a signed release-ZIP build in CI, not new low-level capability.

- **Access admin — remaining screens** — the **Users + Organizations** admin ships (the
  first-party `access` module: `/access/user`, `/access/org` — list/edit/soft-delete via the
  shared DataTables grid + `/api` services). Core's remaining pieces:
  - **Org soft-delete cascade** — reparent children / handle memberships (soft-delete only flags
    the row today; the FK `ON DELETE` actions fire on a hard delete). Substrate integrity → core.
  - **User credentials** — admin-triggered password set/reset + lock/unlock (auth is core substrate).
  - **Config admin (broader)** — the sidebar's **Settings** link now opens a real CMS/site Settings
    screen (site name + home page → `config` table). Still to build under here: per-org theming +
    translation UIs, and a general **options registry** (declared keys → `config` table → UI, per
    the config-discipline). **Must mask secret keys** (e.g. `mail.smtp.password`) in any such UI —
    never render secret config values. Consider a `secret` flag on the `config` table (masked +
    write-only) and/or encrypting secret values at rest (plaintext in the DB today).

  **Not core's job** — **membership management** (add/remove users in an org, set
  role-on-membership) and the **invite flow** belong to the app or an Account/Team module. Core
  ships the `org_user` substrate + `roleOf` + ACL, not the team UX (single-org vs B2B teams vs
  hierarchies each want a different flow). The Users list shows a *read-only* membership summary;
  editing that relationship is the app's call — same boundary as "Account is a module, not core".

- **Authentication UX epic** — building the full sign-in surface, foundation-first. Substrate is
  already built (`user_credential[totp]` + lockout, `auth_challenge` issue/redeem):
  - **✅ `Tiger_Mail`** — Zend_Mail wrapper; `mail()`/SMTP+TLS transports; config-driven.
  - **✅ Clean login / logout** — themed `auth` layout + card, AJAX login, return-to-intended-page.
  - **✅ Lockscreen** — "Lock Screen" in the admin user menu arms a session `locked` flag; the
    Authorization plugin then authorizes the session AS GUEST — public resources still render, but
    anything requiring auth (pages and `/api` services alike) bounces to the return-aware
    `/auth/lock` card until the user re-verifies their password. Identity is untouched (not a logout).
  - **✅ Forgot password** — request-reset (emails a tokenized, single-use `auth_challenge` link via
    `Tiger_Mail`; no account enumeration) + a policy-checked, history-aware reset screen. The login
    "Forgot your password?" link is now live. **Delivery is wired on tiger-dev**: SES SMTP
    (`webtigers.com`, DKIM-signed, out of sandbox) via a dedicated least-priv IAM user
    (`webtigers-ses-smtp`), with `mail.*` creds in the **DB `config` table** (scope=global), not
    in source. Default `core.ini` transport stays `mail()`; the DB row overrides to `smtp`.
  - **✅ OTP code login (email)** — "email me a code" passwordless sign-in: a 6-digit
    `auth_challenge` code (attempt-limited, expiring, hourly send-cap, no enumeration), a two-step
    `/auth/otp` card + a login-page link. Verify/establish is **channel-agnostic**
    (`_completeCodeLogin`), so SMS is a small additive future — instructions are baked into the
    service; the "SMS / OTP flow" item below is now just the SMS channel + a `Tiger_Sms` transport.
  - **TOTP 2FA (authenticator app)** — vendored RFC-6238 (`Tiger_Totp`) + a `totp` credential;
    enrollment (QR from `otpauth://`, no CDN) + a 2FA step after password. The gold-standard one.

- **SMS / OTP flow (the SMS channel)** — email OTP login is done; SMS is the remaining channel.
  Needs a **`Tiger_Sms` transport** (a `Tiger_Mail` sibling — SNS/Twilio, creds in DB config) +
  `requestLoginCodeSms`/`verifyLoginCodeSms` that reuse the channel-agnostic `_completeCodeLogin`
  with type `sms_otp`. Step-by-step instructions are already in `Tiger_Service_Authentication`'s
  OTP section. Substrate built (`auth_challenge` + the `user_credential` `sms` factor).
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

- **Automated test suite + CI — none yet for Tiger's own code (gates 1.0).** TigerZF carries its
  upstream ZF1 test baseline, but `tiger-core` (kernel, ACL, auth, crypto, CMS, the `/api`
  dispatcher, the module installer) and the first-party modules (incl. TigerDocs' scan engine +
  docblock reference generator, and the coming update system) ship **untested**. Stand up PHPUnit +
  a GitHub Actions CI workflow and backfill coverage — **security-critical paths first** (auth/login
  + lockout, ACL deny-by-default, crypto/pepper rotation, module-install extraction + zip-slip guard,
  the `/api` reserved-module guard). A green suite is the gate for the stable **1.0** that freezes the
  `@api` (see §13 of [ARCHITECTURE.md](ARCHITECTURE.md)).
- **Error pages not i18n-keyed** — `core/views/scripts/error/error.phtml` uses literal English;
  key it to `core.error.*` (the keys are already seeded in `core/languages/`).
- **Packagist webhooks** — only TigerZF has its per-repo Packagist auto-update hook; add one
  for TigerCore + Tiger before their first tagged release (or install the org-wide Packagist
  GitHub App).
- **`Zend_Version` secondary constant** — the "latest stable available" constant is still on an
  old value; align it in a TigerZF patch.

## Later / maybe

- **TigerDocs: resizable asides (docs full-width "Phase 2").** Phase 1 shipped in TigerDocs
  **v0.5.0-beta** — a header Normal | Full-width toggle (3/6/3, capped prose, remembered per-browser
  in localStorage `tigerdocs.layout`, applied pre-paint). Phase 2 = drag-resizable left/right asides:
  refactor the doc grid to CSS-variable tracks (`grid-template-columns: var(--docs-left) minmax(0,1fr)
  var(--docs-right)`), add splitter handles + ~50 lines of vanilla pointer-drag (clamp min/max,
  persist the widths into the same localStorage key, restore on load), a reset control, and a mobile
  fallback (stacked, resize disabled below `lg`). Power-user polish; not a priority.
- **Redis session handler** — a swap-in alternative to the DB session handler for scale.
- **Bootswatch full-look skins** — current skins are CSS-variable overlays; a per-skin full
  base-swap if a pixel-perfect Bootswatch theme is ever wanted.
- **Validator message translation** — `Zend_Validate::setDefaultTranslator` so validator
  messages localize (optional).
