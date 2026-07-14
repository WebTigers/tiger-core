# Tiger — Code Modules & the Code Area

How the community shares **code snippets** — a single helper function or a whole class of them that
become available inside TIGER — through a `code` module, and how a user activates them in the **Code
Area**. Read this before touching the snippet store, the runtime loader, or the Code Area screen. For
the platform *why* read [ARCHITECTURE.md](ARCHITECTURE.md); for the sibling theme design read
[THEMES.md](THEMES.md); for the registry read the Vendor Registry docs; for the admin-screen template
read [ADMIN.md](ADMIN.md).

> **Status: BUILT (core), with roadmap edges.** The Code Area, the `code` store, the compile-and-cache
> runtime, local + module snippets, and the registry `type:code` listing all ship today. The security
> **hardening** edges — a static red-flag scan and source-hash integrity pinning — are roadmap (§5).
> Where a section says "roadmap," it isn't built yet; everything else describes running code. This doc
> was reconciled to the implementation on 2026-07-14 (it originally proposed a `code_snippet` table +
> per-snippet `require_once` loader; the built system uses the **`code` table** + a **compiled, validated,
> cached bundle**, and module snippets are **file-based discovery + a config active-set**, never copied
> into the DB — see §3–§4).

---

## 0. The one principle

**A code module is a *pack of snippets*; a snippet is the *activatable unit*.** Installing a code
module never runs anything — it just surfaces its snippets in the Code Area. Activating a **snippet**
is what loads its PHP so its functions/classes/hooks become available app-wide. Two deliberate steps,
exactly like a theme (install ≠ activate).

This is the WordPress "Code Snippets" idea made first-class: a way to share small, useful (or
esoteric) helpers without standing up a whole routed module. The heavy machinery — routes, ACL,
views — is what `app`/`plugin` modules are for; `code` is the lightweight path for *just some PHP*.

---

## 1. The two levels — and the two *sources*

| Level | What | Unit of… |
|---|---|---|
| **Code module** (`type: code`) | a distribution package — one or more snippets + a manifest | **install** (registry / URL / upload) |
| **Snippet** | one self-describing PHP file (or a local DB row): a function, a class, a set of hooks/shortcodes | **activation** (in the Code Area) |

A snippet reaches the Code Area from one of **two sources**, and they store differently — this is the
crux of the reconciliation, so it's stated up front:

| Snippet source | Where it lives | Reviewed? | Stored as |
|---|---|---|---|
| **Module snippet** | a **file** in an installed `code` module's `snippets/` dir | yes — registry review + your read | **stays a file**; discovered live, never copied to the DB |
| **Local snippet** | authored in-app in the Code Area | no — it's *your* code | a row in the **`code` table** (the body is the row) |

Both surface in **one** Code Area datatable and activate identically. Module snippets are the
**community-sharing** path (files are the source of truth); local snippets are the
**write-your-own-helper** path (the core WP-Snippets UX). One screen, two origins, one activation UX.

**Why module snippets are never copied into the DB.** A module snippet's file *is* the source of
truth. Copying its body into a `code` row on install would fork it — a module update would then have
to reconcile against a possibly-edited copy (the WordPress "theme owns a row" trap, THEMES §0). Instead
the runtime reads the file **live**, so a module update flows through automatically and an uninstall
removes the snippets cleanly. "What's *active*" is the only state we keep (see §3).

---

## 2. What a code module ships

```
TigerCodeSnippets/            (the repo; installs as modules/<slug>/, e.g. modules/code-utils/)
  module.json           ; type=code (via keywords), slug, version, requires, provides.snippets="snippets"
  TIGER.md              ; human description (the registry "View more")
  snippets/
    <name>.php          ; ONE snippet — a self-describing PHP file (header hint + body)
  README.md  LICENSE  media/logo.jpg
```

A **snippet file** is self-describing via a leading comment hint — the same convention as TigerDocs'
`tiger:doc`, the theme's `tiger:page`/`tiger:block`:

```php
<?php
// tiger:snippet label="Slugify" category="String" scope="global"
//   description="slug($s): turn any string into a lowercase, URL-safe slug."

if (!function_exists('slug')) {
    function slug(string $s, string $sep = '-'): string { /* … */ }
}
```

- The snippet's **key** is derived from its location — `<module-slug>/<filename>` (e.g.
  `code-utils/slug`) — *not* an `id` attribute. Rename the file, rename the snippet.
- The **hint** attributes (`label`, `category`, `scope`, `description`) drive the Code Area listing and
  search. `scope` maps to a run location — `global` (available everywhere) or `admin` (admin requests
  only) — and the compiler decides when to include it. `Tiger_Code_Modules::_parseHint()` reads the
  leading `//` comment block, so attributes may span comment lines.
- The **body** is ordinary PHP that *defines* things (functions, classes, `Tiger_*` registrations —
  a shortcode, a hook, a service helper). It must be **idempotent and side-effect-light on load**:
  *define, don't do*. Guard definitions (`function_exists`/`class_exists`) so a name collision degrades
  to a reported conflict, not a fatal redeclare.

No `snippets/` folder ⇒ it isn't a code module; `type:code` is a search label, the `snippets/` dir is
the capability (capability-detection, per THEMES.md).

---

## 3. The store — the `code` table + a config active-set (not a `code_snippet` table)

**Snippets are files (module) or `code` rows (local); *what's active* is data.** There is no
`code_snippet` table — the store is two things that already exist in the platform:

### 3a. Local snippets → the `code` table (migration 0021)

The **`code`** table (a standard-columns model, `Tiger_Model_Code`) is the source of truth for
*local* snippets. Its shape is richer than "just PHP" — it's the whole in-app code tier:

| Column | Meaning |
|---|---|
| `code_id` | UUID PK |
| `org_id` | scope owner — **`''` (platform) for server-executed PHP** (the security boundary, §5) |
| `name` / `description` | label + summary |
| `language` | `php` · `phtml` (server) · `js` · `css` · `html` (client) |
| `code` | the body (the snippet *is* this column, for local snippets) |
| `run_location` | `global` · `admin` · `frontend` · `page` |
| `auto_insert` | `head` · `footer` (client tier only) |
| `priority` | load order within a location |
| `active` | `TINYINT(1)` |
| `status` | `draft` · `active` · `error` (the auto-deactivate rail flips this) |
| `last_error` | the error that killed it (populated by the self-heal, §4) |
| standard columns | `deleted`, `created_by`, `updated_by`, `created_at`, `updated_at` |

There is **no** `source`, `module_slug`, `ref`, or `source_hash` column — module snippets aren't rows
(§3b), and integrity is a compile gate, not a stored hash (§4/§5).

### 3b. Module snippets → files + a config active-set

A module snippet has **no DB row at all**. `Tiger_Code_Modules` (a static discovery class) globs
`{APPLICATION_PATH,TIGER_CORE_PATH}/modules/*/snippets/*.php`, skips any module whose slug is in
`Tiger_Model_Module::inactiveSlugs()`, and parses each file's `tiger:snippet` hint. The **active set**
is a single config value:

```
tiger.code.modules = "code-utils/slug,code-utils/time-ago"   ; comma-separated active snippet keys
```

This is the **live-override pattern** (a config-tier value effective next request, config-discipline) —
*not* a `wp_options`-style blob and *not* a new table. `Tiger_Code_Modules::setActive($key, $on)` flips
a key in this set; `body($key)`/`source($key)` read the file on demand. Activating a module snippet
writes **one config key** and rebuilds the bundle — it copies nothing.

### 3c. The version token

Both tiers are invalidated by one already-loaded config token, `tiger.code.version` — bumped on every
save/activate/deactivate/delete. It's the cache key for the compiled bundle (§4), so a request costs
**one config read**, never a snippet query.

---

## 4. The runtime — compile to one validated, cached bundle (not per-snippet `require_once`)

The loader is **`Tiger_Code_Runtime`**, not an `_initSnippets()` that `require_once`s each file. The DB
+ files are the source of truth; each request executes a **single compiled cache file**, and cache
invalidation rides the `tiger.code.version` config token that's already loaded (no query).

**`boot($location)`** — the per-request loader (called once per run location): kill-switch → version →
compile-if-missing → arm the guard → `include` the bundle.

**`compile($location, $version)`** — assembles one bundle and validates it *as a whole* before it goes
live:

1. Concatenate the active **PHP `code` rows** for the location (platform scope, `org_id = ''`) —
   each preceded by a `$GLOBALS` marker naming the snippet, so the guard can identify one that fatals.
2. Append the active **module-snippet file bodies** (`Tiger_Code_Modules::activeForLoad($location)`),
   each `php -l`'d on its own first so one broken file is skipped rather than failing the whole bundle.
3. `php -l` the **entire assembled bundle** out-of-process. This catches parse errors **and
   cross-snippet redeclarations** the per-snippet lint can't see. Only a valid bundle is promoted
   (atomic rename); an invalid set never goes live. **This is what makes bricking impossible.**

**`rebuild()`** bumps `tiger.code.version` + recompiles; the admin service calls it on every mutation.
It's transactional-in-spirit: if the new active set won't compile, the version stays put, the last-good
bundle keeps serving, and the caller surfaces the error (`_safeRebuild` for local rows; the module
toggle rolls its config flag back — see the service).

**The client tier** (`js`/`css`/`html`/`phtml`) compiles separately (`compileClient()`) into versioned,
browser-cacheable **public assets** + a private **inject manifest** that `Tiger_View_Helper_CodeInject`
emits into the page head/footer. Client code can't brick the server, so this path is best-effort and
never throws.

**Fail-soft self-heal.** A shutdown guard (`register_shutdown_function`) catches an *uncatchable* fatal
that happens *while a snippet is loading*: it reads the `$GLOBALS` marker, marks that row `status=error`
+ `last_error`, and rebuilds — so the **next** request recovers automatically instead of white-screening
forever. Catchable errors (PHP 8's `TypeError`, undefined function, …) are handled inline the same way.
A **kill-switch** (a `storage/cache/code/DISABLED` file, or `tiger.code.enabled = 0`) is the fastest
recovery of all.

> Why a compiled file and not `eval`, and not a folder of `require_once`s: the bundle is a **real file** —
> debuggable, OPcache-warm, present in stack traces — validated once and included once. That's the §8
> rejection of `eval` honored, at bundle granularity.

---

## 5. Security — the load-bearing section

**Activating a snippet runs community PHP inside your app. There is no true PHP sandbox.** This
feature makes trust **explicit and informed**; it does not make untrusted code safe. Everything below
is about *accountability and consent*, and the residual risk is real — the same risk WordPress "Code
Snippets" carries, with more rails.

**Built today:**

1. **Superadmin-only.** Installing a code module *and* activating any snippet is gated to `superadmin`+
   (the `code.execute` privilege; the god `developer` role inherits it). The whole `code` module's
   controller + service are ACL-gated to `superadmin` in `configs/acl.ini`, deny-by-default. Never a
   lower role.
2. **Platform scope for server PHP.** Executed PHP is compiled **only** from `org_id = ''` rows — a
   tenant can never inject server PHP into the shared runtime. That boundary is enforced in `compile()`,
   not just the UI.
3. **No auto-activation, ever.** Install/discovery surfaces snippets **inactive**. Activation is a
   separate, deliberate click — the install ≠ activate rule.
4. **Read-before-you-run.** The Code Area shows a module snippet's **full source inline** (a *View
   source* modal, read live from the file via `Code_Service_Code::moduleSource`) before you activate.
5. **Parse-check + whole-bundle validation.** Every server snippet is `php -l`'d on save and on
   activate, and the *assembled bundle* is validated before promotion (§4) — a broken snippet can't
   fatal on load, and a redeclare-conflicting set never goes live.
6. **Fail-soft auto-deactivate rail.** A snippet that fatals while loading is deactivated + flagged +
   logged (`Tiger_Log`), and the site self-heals next request (§4).
7. **Registry review, hardest bar.** Code modules go through the same public review gate as any module
   (presence-based: `module.json`/`snippets/`), and a `code` listing carries a prominent **"runs
   in-process — review before activating"** caution on its directory card.

**Roadmap (not built — don't assume it's there):**

- **Static red-flag scan at activation** — a token pass to block/hard-warn on the obvious footguns
  (`eval`, `exec`/`shell_exec`/`system`/`passthru`/`proc_open`, backticks, `create_function`, dynamic/
  remote `include`, `base64_decode`→`eval`, request-fed variable-variables). Not foolproof, but it stops
  the careless and the obvious. **Not yet implemented.**
- **Integrity pinning (`source_hash`)** — capture a hash at activation and re-verify on load,
  auto-deactivating on a mismatch (a tampered/swapped file). The current model instead reads module
  snippets **live from files** (so a module update flows through with no re-consent) and relies on the
  compile gate + review; hash-pinning + re-consent-on-update is the open hardening (see §10). **Not yet
  implemented.**
- **Full audit trail** — install/activate/deactivate are logged via `Tiger_Log` in the runtime; a
  first-class who-changed-what history is an app concern (ARCHITECTURE §7a), not built here.

The honest summary: **this is trusted code-sharing behind superadmin + platform-scope + read-before-run
+ a validated compile gate — not a sandbox.** Ship it with that framing in the UI, not a false sense of
safety.

---

## 6. The Code Area (admin screen)

A first-party **`code` module** (`modules/code`), reachable at **`/code`** (`Code_IndexController` +
`Code_Service_Code`), built per [ADMIN.md](ADMIN.md):

- **List** — a server-side **DataTables** grid whose rows come from `Code_Service_Code::datatable`
  over `/api`. It **merges** both sources: local `code` rows + discovered module snippets (paginated
  together in PHP, since they have no shared DB order). Each row: name · language · run-location ·
  priority · state, an **active toggle**, and actions.
- **Module rows are read-only** — a `module` badge (naming the owning module), a **View source**
  button (the modal, §5.4), and an activate/deactivate toggle. No edit/delete (the file is owned by the
  module). Their `code_id` is `module:<key>`; the toggle routes that to the config active-set (§3b).
- **Local rows are editable** — a **CodeMirror** PHP editor (`themes/puma/.../vendor/codemirror`, the
  `TigerCodeEditor` helper) to author/paste PHP, name it, set scope, and save; save runs the same
  parse-check. Edit + soft-delete/restore are local-only.
- Toggling/saving posts to `/api` (validate → `rebuild()`), effective next request, using the house UI
  primitives (`TigerButton`, `TigerDOM`) — see AGENTS.md.

---

## 7. Registry integration

- **`type: code`** in the listing (the schema enum + the Add-Module type filter — built).
- A code module installs through the **same path** as any module (`module.json` + `TIGER.md`, the
  `installFromUrl`/`installFromUpload`/registry flow). **Post-install nothing is inserted into the DB** —
  `Tiger_Code_Modules` discovers the `snippets/` **live** and they appear in the Code Area (inactive)
  via the datatable merge. Activation is the config flag (§3b), not a row.
- The directory card shows the **Code** badge and the **"runs in-process — review before activating"**
  caution; "View more" surfaces the `TIGER.md`.
- **First code module, listed:** `WebTigers/TigerCodeSnippets` (slug `code-utils`, MIT) — 6
  dependency-free helper snippets — is accepted in the Vendor Registry (`type:code`, `category:Developer`,
  pinned `v0.1.0-beta`).

---

## 8. Rejected alternatives (so we don't relitigate)

| Rejected | Why | Chosen instead |
|---|---|---|
| **Copy a module's snippets into the DB on install** | forks the file → update reconciliation hell (the WP "owns a row" trap) | **files stay the source of truth**; discovered live; only the active-*set* is data (§1, §3b) |
| A dedicated `code_snippet` table with a `body` column | duplicates what the `code` table already is; strands module snippets as rows | local → the **`code` table**; module → **files + a config active-set** |
| `eval()` snippet code | no debuggability, no OPcache, hidden in stack traces | compile active snippets into **one real, `php -l`-validated bundle file**, included once (§4) |
| Per-snippet `require_once` at bootstrap | N includes/request, no cross-snippet validation, easy to brick | one compiled bundle, whole-bundle lint gate, version-token cached |
| Auto-activate on install | surprising + dangerous — runs code you haven't read | install/discovery surfaces *inactive*; explicit activate (§5.3) |
| A full routed module per helper | too heavy for "just a `slug()` function" | that's what `plugin`/`app` are; `code` is the light path |
| Namespaced/isolated snippet scope | defeats the feature (the point is `slug()` *app-wide*) | global scope + `function_exists` guard + the compile-gate conflict report |
| A `wp_options`-style blob of active ids | not queryable, grab-bag | the `code.active` column (local) + a declared `tiger.code.modules` config key (module) |
| "It's sandboxed, so it's safe" framing | false — PHP isn't sandboxable here | superadmin + platform-scope + read-before-run + compile gate, stated honestly |

---

## 9. Build order (phasing) — status

1. **The `code` store** (migration 0021) + `Tiger_Model_Code` + the active-set + version token. **DONE.**
2. **The runtime** — `Tiger_Code_Runtime`: compile-and-cache, whole-bundle validation, fail-soft
   self-heal, kill-switch; the client tier + `CodeInject`. **DONE.**
3. **The Code Area** — the `code` module: list + activate/deactivate + View source + the local
   CodeMirror editor. **DONE.**
4. **Module snippets** — `Tiger_Code_Modules` (file discovery + config active-set), `compile()` appends
   active files, the datatable merge + `moduleSource`, the module toggle + View-source modal. **DONE
   (2026-07-14).**
5. **Registry** — `type:code` filter + the presence-based review gate + the first `code` listing. **DONE.**
6. **Security hardening** — the static red-flag scan, `source_hash` integrity + re-consent-on-update,
   a first-class audit trail. **ROADMAP (§5).**

---

## 10. Open questions (decide before hardening)

- **Integrity vs. live-update.** Module snippets read live, so a module update flows through with no
  re-consent — convenient, but it means approved code can change under you. Add `source_hash` pinning +
  a re-consent prompt on update? (The trade-off: friction vs. tamper-evidence.)
- **The red-flag scan's bite** — block outright, or hard-warn + require an extra confirm? And where does
  it run — activation only, or also the registry review bot?
- **Scope beyond global/admin/frontend/page?** e.g. a `cli` scope for console helpers.
- **Dependencies/ordering** — may a snippet declare it needs another loaded first? (Priority handles
  order today; not declared dependencies.)
- **Provided-API discovery** — should a snippet's public functions feed the docblock reference
  generator, so a code module self-documents what it adds?
- **Export a local snippet → a code module** — an "author here, share to the registry" on-ramp?

---

*This document records decisions and their rationale. If you change a decision, update the relevant
section here in the same change — the "why" is the most valuable and most perishable part.*
