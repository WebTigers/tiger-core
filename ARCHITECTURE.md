# Tiger — Architecture & Rationale

This document explains **why Tiger is built the way it is**. It's meant to be ingested by
an AI or read by a human before changing anything, so decisions aren't accidentally undone.
It favors *rationale* over reference — for "what/how", read the code and the READMEs; for
"why did they do it this way", read here.

Tiger is a **1-click SaaS platform** built on [TigerZF](https://github.com/WebTigers/TigerZF)
(Zend Framework 1, modernized for PHP 8.1–8.5). It gives you the boring-but-essential SaaS
substrate — multi-tenant orgs, users, memberships, roles/ACL, auth, theming — so you build
*product*, not plumbing.

> Reference architecture: **AskLevi** (a legal SaaS built on TigerZF). Tiger generalizes
> AskLevi's clean patterns. Where this doc says "AskLevi does X," that's the proven pattern
> we adapted. The old **TigerCore** repo is the *anti*-reference (the copy-not-consume
> mistake we're correcting).

---

## 0. The prime directive: ownership

**Every file is owned by exactly one party, and the boundary is enforced by tooling.**

- **`vendor/` is Tiger-owned.** `composer update` replaces it in place.
- **Everything else is app-owned.** Composer *cannot* write outside `vendor/`, so your code
  is physically safe from updates.

From this one rule everything else follows:

- **Extend, don't edit.** You customize by *adding* — modules, config overrides, subclasses,
  skins — never by editing a Tiger-owned file. Nothing *stops* you editing something in
  `vendor/`, but it vanishes on the next `composer update`. We don't forbid; we make the
  consequence predictable. (Forbidding is a fiction — someone always finds a reason. Honest
  consequences are what actually keep people on the upgrade path.)
- **Update safety = a versioned public API.** Tiger-owned code marks its surface with
  `@api` (stable, semver-guaranteed) vs `@internal` (may change any release). Apps depend on
  `@api` only.
- **Core may never import a module.** Arrows point *modules → Core*, never the reverse. Core
  is the thing every app needs and that needs no app.

If you're ever unsure where something goes, ask: *"Who owns this, and what happens to it on
`composer update`?"* The answer is usually the design.

---

## 1. Distribution: skeleton + framework (the Laravel model)

Tiger ships as **two packages**, exactly like Laravel (`laravel/laravel` + `laravel/framework`)
or Symfony (`symfony/skeleton` + `symfony/symfony`):

| Package | Role | Lives | Owner |
|---|---|---|---|
| **`webtigers/tiger`** | the **skeleton** you `composer create-project` | becomes your app dir | you (copied once) |
| **`webtigers/tiger-core`** | the **framework** | `vendor/webtigers/tiger-core` | Tiger (updatable) |

**Why:** classic ZF1 is "copy files to a folder, `/public` is your docroot, done" — simple,
but with *no update path* (you copied the framework in; now you can't update it without
clobbering your changes — the WordPress-hack trap). The skeleton+framework split keeps the
"copy once, it's yours" feel for the skeleton *and* gives the framework a real `composer
update` path. `create-project` copies the skeleton once; you never update it. The framework
lives in `vendor/` and updates safely.

**Rejected:** one repo per module (Laminas-style polyrepo). Modularity ≠ repo count; a graveyard
of ~15 module repos is a maintenance tax with no benefit. Tiger is a **monorepo** for the
framework + first-party modules.

---

## 2. The four-layer stack

```
Zend_*   → TigerZF, the ZF1 engine        vendor/webtigers/tigerzf        Tiger-owned
Tiger_*  → the platform (kernel + core)   vendor/webtigers/tiger-core     Tiger-owned
App_*    → your app's shared code         library/  (or src/ for PSR-4)   app-owned
Firm_*…  → your features (modules)        application/modules/*           app-owned
```

- **`Tiger_*`** is the platform layer — the kernel (bootstrap, ACL, auth, service dispatch)
  plus the substrate. Composer-autoloaded from `vendor/`, exactly like `Zend_*`.
- **`App_*`** (the app `library/`) is for **shared code with no routes/UI** — base classes,
  helpers, integrations, cross-module services. The killer use: subclass Tiger's base classes
  *once* (`App_Service_Base extends Tiger_Service_Service`) and have every module extend
  *your* base — you customize Tiger's extension points without touching Core and without
  repeating yourself. Rule of thumb: *shared plumbing → library; a feature (controllers/
  routes/ACL/views) → module.*
- **Modules** are features.

**Namespaces:** PSR-0 underscore is the house default (matches ZF1). PSR-4 is co-registered
and never blocked for your own `library/`/module code. The one hard constraint: **ZF1
mandates PSR-0 underscore for controllers and module classes** — a module's *services/models*
can be PSR-4, its *controllers* cannot.

---

## 3. Core = the default namespace (there is no "core module")

Core owns ZF1's **default (module-less) namespace**, and it's **sourced from the
`tiger-core` package** (its controllers/views/config are wired in from `vendor/`).
`application/modules/*` is for **new functionality only** — every module is, by definition,
an extension.

**Why:** it makes the boundary *structurally enforceable*. "If it's in `/modules`, it's an
add-on; if it's in the default namespace, it's Core" — no "is core a module?" ambiguity, and
nobody can accidentally treat Core as just-another-module. AskLevi's `core` module conflated
"the platform" with "this app's public face"; splitting Core into the package untangles them.

You *can* drop a default-namespace controller into `application/` if you insist (it wins via a
cascade) — not recommended, never prevented, and safe from updates because it's app-owned.

### 3a. What tiger-core *is* — a framework distribution, not a module

tiger-core looks unusual at first: one Composer package that ships a **library** (`Tiger_*`
classes, **including models**), a **default-namespace MVC surface** (`core/controllers`,
`core/views`), **first-party modules** (`modules/*`), a **theme**, **config**, **migrations**,
and a **CLI**. That multi-role shape can read as esoteric — even a "hack" — on ZF1, which
predates Composer-as-culture and has no first-class "a package contributes MVC" concept. It
isn't a hack; it's the mainstream **framework-package / bundle** pattern, retrofitted onto ZF1:

- **The right mental model is `laravel/framework` or a Symfony bundle**, *not* "a big module."
  A module plugs *into* an app; tiger-core is the layer the app and its modules *sit on*.
  Laravel's framework package and every Symfony bundle ship exactly this mix — base
  controllers, views, config, commands, migrations, assets — in one installable unit.
- **One directory per ZF1 resolution mechanism.** The layout is *more* ZF1-native than it looks:

  | ZF1 locates it by… | tiger-core dir | Holds |
  |---|---|---|
  | **Autoload** (class → file) | `library/Tiger/*` | anything addressed by class name: bases, services, ACL, forms, the CMS engine, **and models** |
  | **Controller dispatch** (default ns) | `core/controllers`, `core/views` | the module-less MVC face |
  | **Module scan** | `modules/*` | first-party routed features (e.g. `cms`) |
  | **View path / config merge** | `themes/*`, `configs/` | theme + the config cascade |

- **Why models live in `library/`, not `application/models/`.** The ZF1 habit of models in
  `application/models` is a convention for **app-owned, module-scoped** models. tiger-core's
  models are neither — they're **platform substrate** (`@api`), autoloaded and consumed from
  `vendor/`, peers of `Tiger_Service_Service` and `Tiger_Acl_Acl`. `Zend_Db_Table_Abstract`
  lives in a library; `Tiger_Model_Org` is the same *kind* of thing. Putting them beside the
  rest of `Tiger_*` is the **consistent** choice — the split-brain would be scattering them
  into a `core/models/` while the services sit in the library.
  - **The rule to police:** a class in `library/Tiger/Model` must be true platform substrate —
    reusable by any app or module, depending on nothing app-specific. A model tied to one
    feature belongs in that module's `models/`. (The CMS is the edge case: the content *store*
    — `Tiger_Model_Page`/`PageVersion`/`PageRedirect` — is substrate like `user`/`org`, so it's
    library; the CMS *admin* is a feature, so it's `modules/cms`. Engine in the library, feature
    in a module — the same split, applied honestly.)

- **The one deliberate novelty: the package ships *updatable* MVC.** Handing controllers/views
  to a Composer package (dispatch-resolved, hand-wired in `Tiger_Application_Bootstrap::
  _initTigerPaths` via `addControllerDirectory`) is the part with no ZF1 precedent. The
  "cleaner" alternative — tiger-core as pure library, with the MVC/theme/modules shipped in the
  **skeleton** — was rejected on purpose: skeleton files are copied *once* and can never be
  `composer update`d, which is exactly the copy-in trap §1 and §12 reject. Shipping the default
  MVC in the updatable package is the *reason* for the unusual shape, not an accident of it.
  Don't "simplify" it back into the skeleton without re-reading this.

---

## 4. Entry & bootstrap

**`public/index.php` is a 3-line shim.** Everything tidy lives in the package:

```php
define('APPLICATION_ROOT', dirname(__DIR__));
require APPLICATION_ROOT . '/vendor/autoload.php';
(new Tiger_Application(APPLICATION_ROOT))->run();
```

**`Tiger_Application`** (package) is the front door. It does, in order:

1. **Proxy/ALB normalization** (see §4a) — critical for running behind a load balancer.
2. **Path constants** (`APPLICATION_PATH`, `TIGER_CORE_PATH`, …) + `set_include_path`.
3. **`custom.php` hook** — an optional app-owned file loaded after autoload, before boot. This
   is where app-level entry code goes (app constants, helper functions, pre-bootstrap tweaks),
   so `index.php` stays thin and you never edit Tiger's entry plumbing. It survives updates.
4. **Config cascade** (see §5) → hands a merged `Zend_Config` to `Zend_Application`.
5. **Guarded dispatch** — `try/catch` around `bootstrap()->run()`: log + HTTP 500, with a
   stack trace in non-prod and a generic message in prod.

**`Tiger_Application_Bootstrap`** (package base class) is what the app's `Bootstrap` extends:

```php
class Bootstrap extends Tiger_Application_Bootstrap {}   // application/Bootstrap.php
```

So the app inherits module scanning, default-namespace wiring, theme-as-path, and the config
publish **for free** — core bootstrap logic is *inherited*, never copied into the app. Add your
own `_init*` methods in the subclass to hook the sequence.

**Why the base class + shim?** AskLevi's `constants.php`/`index.php`/`Core_Bootstrap` are
hand-maintained files *in the app*. That's fine when you edit your own core module — but Tiger's
core is a package you don't edit, so the entry plumbing moves into the package (updatable) and
the app keeps only a thin shim + the `custom.php` hook.

### 4a. Behind an ALB (the "fun little pieces")

A load balancer terminates TLS and forwards the real client info in `X-Forwarded-*` headers.
PHP otherwise sees plain `http` on port 80, which breaks client-IP logging and makes ZF1 build
`http://` URLs (→ redirect loops / mixed content). `Tiger_Application::normalizeProxy()` fixes it:

- `X-Forwarded-For` → `$_SERVER['REMOTE_ADDR']` (leftmost = original client).
- `X-Forwarded-Proto: https` → set `$_SERVER['HTTPS']='on'` **and** `SERVER_PORT=443` so
  `Zend_Controller_Request_Http::getScheme()` builds correct `https` URLs and redirects.
- Exposes an `HTTPS` boolean constant.

---

## 5. Config cascade

Four tiers, each owned by the right party, merged later-wins:

```
core.ini          vendor/…/tiger-core/configs   Tiger    bootstrap plumbing (frontController, modules, theme defaults)
  ← application.ini   application/configs        app      app settings + overrides (theme/skin, site)
    ← local.ini       application/configs        app      secrets / per-deploy (gitignored; .dist template shipped)
      ← DB (org-scoped)                          runtime  per-org overrides, no deploy
```

- **Merge happens in `Tiger_Application::buildConfig`** (ini tiers) and
  `Tiger_Application_Bootstrap::_initConfigs` (DB tier), producing the `Zend_Config` the app sees.
- **Environment inheritance** within each ini file: `[production]` is the base;
  `[staging|testing|development : production]` inherit it. Every loaded ini declares **all four
  sections** (even empty) so the per-file `Zend_Config_Ini($file, $env)` load can't hit a missing
  section. testing sets `throwExceptions=1` (CI catches them); development shows errors but routes
  to the error page (stays browsable); staging mirrors prod.

**Why separated?** AskLevi keeps *everything* in one `application.ini` — which works **only
because its core is a copied module it owns and edits.** Tiger consumes core as a package you
never edit, so the split is *forced by the ownership rule*: core plumbing → `core.ini` (package,
never edited); app settings → `application.ini` (edit freely — there's no core plumbing in it to
break); secrets → `local.ini` (uncommitted — fixing the plaintext-password smell in AskLevi's
committed config).

**The elegant convergence:** AskLevi's config cascade already has a DB layer scoped by
`global` + module. **Add an `org` scope and config resolution *becomes* the per-org theming
resolver** — a tenant's active theme/skin is just an org-scoped config row resolved at
bootstrap. Config resolution and per-org theming are the *same mechanism*.

---

## 6. Module system — how a module plugs into Core

Module discovery is **ZF1's built-in scan**, not hand-rolled:
`resources.frontController.moduleDirectory` + `resources.modules[]` → `Zend_Application_Resource_Modules`
scans the modules dir, includes each `Bootstrap.php`, runs it. Both `application/modules` (app)
and `vendor/…/tiger-core/modules` (first-party) are registered.

A module is **purely additive** — it plugs in by convention, touching no Core file:

- **Config** — its `configs/*.ini` are auto-discovered by convention, no wiring: `routes.ini` folds
  into the config cascade (ROUTING.md); `acl.ini` is read by `Tiger_Acl_Acl`; and `navigation.ini`
  is read by `Tiger_Admin_Nav` at bootstrap to add a top-level admin-sidebar item — the zero-code
  path alongside the `Tiger_Admin_Nav::register()` code path (both coexist).
- **Behavior** — it exposes `Module_Service_*` classes; the core `/api/:module/:service/:action`
  route hits one thin `ApiController` that resolves the target service by convention and returns
  JSON. **Thin controllers, fat services.**
- **Permissions** — resources are class names (`Module_Controller_*`, `Module_Service_*`); every
  access goes through `Zend_Acl::isAllowed($role, $resource, $privilege)`.
- **Views/i18n/routes** — same additive pattern.

**Activation is zero-infra.** A module **never touches infrastructure** — no Apache/nginx config,
no filesystem outside its own dir, no DNS. It works the moment it's activated, on any install.
This is why a pretty public URL is a **PHP-layer route override** (a `Tiger_Routing_Overrides`
declaration applied by a plugin — see ROUTING.md), *not* a rewrite rule: a rewrite rule would make
installing a module require editing the web server, which breaks 1-click install and the ownership
boundary. A SaaS/platform owner may of course change their own deployment's infra — that's their
prerogative — but it is **never** something a module install/activation does or depends on.

---

## 7. The multi-tenant substrate

| Entity | What | Notes |
|---|---|---|
| **Org** | the tenant | self-referential `parent_org_id` → org hierarchies |
| **User** | a person | deliberately **thin** — bare essentials only |
| **`org_user`** | membership | the join table = **tenancy boundary AND role carrier** |

- **Account is a module**, not core — it extends `User`/`Org` via its own FK-linked tables,
  **never by adding columns to core tables.** Why: so the platform can be updated without
  breaking apps that extended it. ("Try not to edit Core files — or Core tables — within your app.")
- **`org_user` is the tenancy boundary.** What stops a user acting across tenants is the
  *absence of an `org_user` row* linking them to that org. Cross-tenant denial is structural,
  not a code check you can forget.

---

### 7a. Standard columns (enterprise boilerplate)

Every domain table carries these, and `Tiger_Model_Table` maintains them
automatically (each applied only if the column exists):

| Column | Purpose |
|---|---|
| `status` | lifecycle state (active/suspended/…) |
| `deleted` | soft-delete flag `TINYINT(1)`; **reads exclude deleted by default** via `activeSelect()` / `findById`, flipped by `softDelete()` / `restore()` |
| `created_by` / `updated_by` | actor stamps (`user_id`) — **no FK** (a stamp must not block deleting the user it points at; `NULL` = system/genesis) |
| `created_at` / `updated_at` | timestamps |

The **current actor** is set by auth on login (`Tiger_Model_Table::setActor()`);
CLI / system inserts leave it `NULL`. **Audit *trails* (who-changed-what history)
are deliberately an app concern** — core ships the stamp columns, not a history
table. (Soft-delete + a UNIQUE column: a deleted row still holds its unique value,
e.g. `org.slug`; reuse policy is the app's call, not core's.)

### 7b. Authentication storage

Auth is separated from identity, and durable *factors* are separated from transient
*challenges*:

| Table | What | Cardinality | Notes |
|---|---|---|---|
| `user` | identity (who you are) | — | pure — no password, no credentials |
| `user_credential` | durable factors (how you prove it) | **1-to-many** | `password` / `sms` / `totp` / `webauthn` / `oauth` — each a row; new types = new rows, no schema change |
| `auth_challenge` | one-time proofs in flight | many | SMS OTP / reset / verify / magic-link; **v4** id (opaque), hashed code, TTL + single-use + attempt-limited |

Why 1-to-many and not a 1-to-1 auth row: modern auth is multi-factor/multi-method
(password **and** SMS **and** TOTP **and** several passkeys **and** SSO), each with
its own verification state — that's a collection. **Phone lives here as an `sms`
factor** (identity authentication only; phone-as-*contact* would be an Account-module
field). Passwords moved *off* `user` into a `password` credential, so `user` is pure
identity. `secret` holds a one-way hash (password), an app-encrypted secret
(TOTP/OAuth), or a public key (passkey) — semantics are per-type, enforced by the
model, not the schema. Login *orchestration* (resolve identity → check factor → issue
session) belongs in an auth **service**; the models are factor-aware gateways.

## 8. ACL

- **Subject-agnostic:** `can(Subject, permission, context)` — the engine doesn't care whether
  the subject is a user, an org, or a token.
- **Role lives on the membership (`org_user`), not on the user.** So the same user can be
  `admin` in one org and `viewer` in another — real multi-tenancy. (AskLevi's ACL is
  single-global-role-per-user; moving the role onto `org_user` is Tiger's key evolution. We keep
  AskLevi's DB-driven hierarchical role *engine* and just relocate the assignment.)
- **Every decision goes through `Zend_Acl::isAllowed`.** Never compare role strings in code.
  "God mode", "can create admins", etc. are expressed as ACL grants, not `if` branches.
- Orgs don't normally have roles (roles are a user-side, membership concept). Nothing prevents
  attaching a role to an org and running an ACL check for it — it's just a rare case.

---

## 9. Theming: theme vs skin

Two axes, deliberately different weights:

| | **Theme** | **Skin** |
|---|---|---|
| Is | a whole **view layer** (layouts, view scripts, structure) | a **CSS-only** override of the theme's `default.css` |
| Weight | heavy, changes rarely | light, swappable, structurally inert |
| Per-tenant? | rare (white-label) | **yes — this is the tenant branding axis** |
| Example | `puma`, a future `react`/`tailwind` theme | `jaguar`, `cheetah`, `<tenant>.css` |

- **`PUMA` is the default theme = vendored Bootstrap 5 (zero build).** No npm, no Sass, no
  PostCSS — just `bootstrap.min.css` + `bootstrap.bundle.min.js`, dropped in. Skins are
  `:root { --bs-* }` (+ component `--bs-btn-*`) variable overrides.
- **Why Bootstrap, not Tailwind:** the principle is *"we hate the build toolchain, not CSS."*
  Bootstrap themes at **runtime** via CSS variables (a skin is a tiny override file); Tailwind
  themes at **build time** (needs a compile). Zero-build is a pillar of the 1-click install:
  `composer install` + an asset symlink and the UI is live — **no npm, no frontend build in the
  deploy.** Node only ever appears if *a theme* opts into a build, quarantined in that theme.

### 9a. Theme = a path (the cheap, powerful mechanism)

Active theme + skin resolve from the config cascade at bootstrap (config now; per-org via the DB
layer). "Active theme" is **just a path** woven into the layout path, the view-script paths, and
the asset base URL. **No inheritance, no routing** — that's the whole trick (straight from
AskLevi's `_initTheme`). The only fallback is **theme → Core default views** (via the view-path
cascade), so a theme only provides what it wants to override.

**Why full themes (not just skins) earn their keep** — a skin can only recolor; a theme is a
whole view layer, which unlocks:

1. **Framework = theme.** Core emits *data* + *semantic default views*; a theme decides the whole
   rendering approach. PUMA is Bootstrap-SSR; a `react` theme is a shell + a bundle hitting
   `/api`. **Frameworks become swappable themes, and Core stays framework-free.**
2. **A/B tests / gradual redesigns / per-cohort UI** — theme resolves per-request from
   org/user/flag, so you roll a new UI to some tenants without a big-bang cutover.
3. **Each Tiger app's identity *is* its theme** — for a platform others build on, the theme is
   the seam between "the platform" and "this product."
4. **Genuine white-label** — different structure/nav, not just colors.

**Held the line at:** **no theme→theme inheritance, no per-module theme override cascade.** That's
the expensive machinery, and it stays unbuilt until something real demands it. Theme-as-a-path is
cheap *and* retrofit-averse (bake the convention in now; hunting hardcoded paths later is the pain
YAGNI actually warns about) — full swap machinery is the speculative feature YAGNI rules out.

**Ownership:** PUMA + stock skins ship in `vendor/…/tiger-core/themes/`. Tenant/custom skins
resolve **app-over-vendor** (`my-app/themes/<theme>/skins/*.css` wins) so they never touch
`vendor/`. Because a skin is structurally inert CSS, tenants can eventually self-author one
safely.

---

## 10. Pathing — how ZF1 resolves each resource from `vendor/`

The reassuring fact: most of it is Composer autoload; only the web layer needs wiring.

| Resource | Where it lives | How it's found | App override |
|---|---|---|---|
| `Tiger_*` / `Zend_*` classes | `vendor/…/library` | **Composer autoload** (zero config) | subclass / DI rebind |
| Core controllers (default ns) | `vendor/…/tiger-core/core/controllers` | `setControllerDirectory('default', …)` | drop one in `application/` (cascade) |
| Module controllers | `application/modules/*` + `vendor/…/tiger-core/modules/*` | `addModuleDirectory` (both) | add your own module |
| Views | package `core/views` + theme + `application/views` | view **script-path stack** (app last = wins) | same-named script in a higher path |
| Config | `core.ini` → `application.ini` → `local.ini` → DB | **merge, later wins** | your `.ini`/DB row |
| Assets | `vendor/…/tiger-core/themes/<theme>/assets` | **symlink** `public/_theme` | app-owned theme/skin files |

Mental model: **classes = Composer (automatic); views/config = cascade (app wins); controllers =
registered dirs; assets = symlink.**

---

## 11. Design principles (the heuristics behind the rules)

- **Consume, don't fork.** Core is a dependency in `vendor/`, never copied into the app.
- **Never hack Core; extend instead** — modules, config overrides, subclasses, skins.
- **Core imports nothing app-side.** Dependencies point one way (modules → Core).
- **Thin controllers, fat services.** Controllers dispatch; services hold logic and are the
  ACL-gated, `/api`-reachable unit.
- **Zero-build frontend.** Vendored assets, runtime CSS-variable theming. A build tool only ever
  enters via a theme that opts in.
- **Secrets are never committed.** `local.ini` (gitignored) / AWS Secrets Manager — never
  `application.ini`.
- **YAGNI, with one exception:** don't build speculative *features*; *do* establish cheap
  conventions that keep doors open (theme-as-a-path), because retrofitting those is the expensive
  part.
- **Prefer the proven pattern.** AskLevi is a working system; when it does something clean, adopt
  it rather than reinventing.

---

## 12. Rejected alternatives (so we don't relitigate them)

| Rejected | Why | Chosen instead |
|---|---|---|
| Repo-per-module (Laminas polyrepo) | maintenance tax; modularity ≠ repo count | monorepo (`tiger-core`) |
| Core as a copied module (TigerCore/AskLevi) | copy-not-consume → no clean update path | Core as a versioned package |
| Single `application.ini` with core plumbing | users edit it → break core on update | 3-tier config split by ownership |
| Full theme→theme inheritance / swap resolver | speculative machinery, rarely needed | theme-as-a-path + core-view fallback |
| Tailwind as the default theme | needs a build toolchain | Bootstrap (runtime CSS-var theming) |
| Role on the User (global) | single-tenant thinking | role on `org_user` (membership) |
| tiger-core as pure library (MVC/theme/modules in the skeleton) | skeleton files copy once → default MVC can't be `composer update`d | package ships *updatable* MVC (see §3a) |
| Models in `application/models`-style dirs | they're platform substrate, not app models | `library/Tiger/Model/*`, peers of the services (§3a) |

---

## 13. Current state (as of 2026-07-16)

**Public beta released.** `webtigers/tiger-core` (**v0.8.1-beta**) and the `webtigers/tiger`
skeleton (**v0.1.3-beta**) are on **Packagist**, on top of `webtigers/tigerzf`. A new app is a
one-liner (proven from a clean room — pure Packagist, no VCS repos):

```bash
composer create-project webtigers/tiger my-app --stability=beta
```

`--stability=beta` drops away at the stable 1.0. Tags self-publish (the Packagist GitHub App is on
the org).

**Built & live** on `tiger-dev.webtigers.com` — a *real vendored install* behind the shared ALB;
Apache is a faithful cPanel mirror (docroot + `public/.htaccess`, no vhost rules):

- **Kernel** — `Tiger_Application` + `Tiger_Application_Bootstrap`, the 4-tier config cascade, ALB
  handling, `custom.php` hook, a self-locating `public/index.php`.
- **Multi-tenant substrate** — `org` / `user` / `org_user` (membership = tenancy + role); auth split
  into `user_credential` + `auth_challenge`; email-or-username login, DB sessions, auto-logout.
- **Webservices** — the `/api` TIGER message dispatcher (`Tiger_Ajax_ServiceFactory`), standard
  response envelope, DataTables server-side processing.
- **Authorization** — `Tiger_Acl_Acl`, deny-by-default, role-on-membership, `acl.ini` + DB.
- **CMS + theming** — `Tiger_Model_Page` store + `Tiger_Cms_Renderer` (md / html / phtml / GrapesJS
  builder), slug dispatch; PUMA theme + `jaguar`/`cheetah` skins (zero-rebuild reskin).
- **Routing overrides** — PHP-layer pretty routes (`Tiger_Routing_Overrides`), zero infra.
- **Admin + UI** — `Tiger_Controller_Admin_Action` + the Settings registry; vanilla UI primitives
  (TigerButton / TigerDOM / TigerValidate) and convenience validation.
- **Modules** — discovery / installer / registry, activate-deactivate (asset symlink on activate,
  lightweight dependency alerts), `make:module` scaffolding.
- **Documentation (TigerDocs)** — the first installable module, now a full docs engine:
  **zero-config, multi-source** (platform content + every active module's own `docs/` folder =
  self-documenting modules), a `public | admin` **visibility** split (the `/docs` site + an in-admin
  help center), ⌘K search, and a per-server fingerprint-invalidated **build cache**. Plus a
  **docblock-driven reference generator** — token-based (no boot), `@api` classes → `tiger:doc` pages
  as a gitignored **build artifact** (`var/docs-generated`, never committed, rebuilt each deploy),
  run from a deploy hook (`bin/build-reference.php`) or a one-click admin **Build reference** button.
  Now serving the platform docs in **production** at `tiger.webtigers.com/docs`.
- **Plus** — media storage adapters, `Tiger_Location` + `Tiger_I18n_Country`, the `bin/tiger` console
  (migrate / install:* / make:module / module:* / crypto / `link:assets`), and the signup reference form.

**Pending (roadmap):** the cPanel/no-shell install track — a pre-built vendored ZIP release-build
plus a browser web installer (the composer path is done; this covers hosts with no shell); a
smoke-test suite + CI ahead of a stable **1.0** (which freezes the `@api`); and deferred
integrations (live AWS Location adapter, front-end GrapesJS, AI review bot).

---

*This document records decisions and their rationale. If you change a decision, update the
relevant section here in the same change — the "why" is the most valuable and most perishable
part of the codebase.*
