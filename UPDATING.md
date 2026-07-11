# Tiger — Updating (platform, core, modules)

How you keep a Tiger install current, layer by layer. This documents the mechanics that **exist
today**; for what's still coming (proactive "update available" badges, the unified one-click
*Updates* screen, the no-shell core self-updater) see [BACKLOG.md](BACKLOG.md). For host
requirements read [INSTALL.md](INSTALL.md);
for *why* the layers update differently read [ARCHITECTURE.md](ARCHITECTURE.md) §1 + §0.

The one rule that makes updates safe: **only `vendor/` is Tiger-owned.** A core/platform update
replaces code in `vendor/`; **everything you own** — `application/`, `library/App`, `local.ini`,
custom themes/skins — is never touched. Extend, don't edit, and updates can't clobber your work.

## What updates, and how

| Layer | Package / format | Version source | Update mechanism (today) |
|---|---|---|---|
| **Skeleton** `webtigers/tiger` | Composer project (copied once) | git tag | you rarely update it; cherry-pick skeleton changes by hand |
| **Core** `webtigers/tiger-core` | Composer package in `vendor/` | `Tiger_Version::VERSION` + git tags (Packagist) | `composer update` |
| **Engine** `webtigers/tigerzf` | Composer package in `vendor/` | git tags (Packagist) | `composer update` |
| **Modules** | plain files in `application/modules/<slug>/` (**not** Composer) | `module.json` `version` + the `module` table | `bin/tiger module:install` |

---

## Core & platform — Composer

Core and the ZF1 engine are ordinary Composer packages, so updating is `composer update`:

```bash
composer update webtigers/tiger-core          # just core (+ its deps)
composer update                               # core + tigerzf + everything
vendor/bin/tiger migrate                      # apply any new schema (always run after an update)
```

- **Version of record** is the `Tiger_Version::VERSION` constant (bumped per release); tags drive
  Packagist. Check what's live with `vendor/bin/tiger version`; check for newer with
  `composer outdated webtigers/*` (there's no in-app "update available" yet — see BACKLOG).
- **Resolution differs by environment:** a dev app that declares a `repositories: [{type: vcs}]`
  entry for TigerCore resolves from **GitHub directly** (a new tag is available instantly); a
  plain install resolves from **Packagist** (a tag may lag a minute behind the GitHub push — use
  `composer update --no-cache` if you just tagged).
- **Beta:** `composer create-project webtigers/tiger my-app --stability=beta`; the flag drops at 1.0.
- **After updating,** run `migrate`, re-`link:assets` if theme assets changed, and clear caches
  (opcache; any module build caches — e.g. `var/cache/tiger-docs`, `var/docs-generated`).

> No shell / cPanel: `composer update` isn't available. The no-shell core-update path (a pre-built
> vendored release ZIP + a browser updater that atomically swaps `vendor/`) is **designed, not yet
> built** — see [BACKLOG.md](BACKLOG.md).

---

## Modules — the module installer

Modules are **not** Composer packages — the repo root *is* the module, and on install its contents
become `application/modules/<slug>/`. `Tiger_Module_Installer` does the work, behind **two
front-ends**: the `bin/tiger` console **and a Module Manager admin screen** (see below).

```bash
vendor/bin/tiger module:install <github-url> [ref]   # install/update from a public GitHub repo
vendor/bin/tiger module:list                         # installed modules, source, version, active
vendor/bin/tiger module:activate <slug>              # activate (publishes assets)
vendor/bin/tiger module:deactivate <slug>            # deactivate (unpublishes; installed-but-off)
vendor/bin/tiger module:remove <slug>                # remove files + assets + the registry row
```

**What `module:install` does** (`Tiger_Module_Installer::installFromUrl` → `installFromTarball`):

1. Resolve the GitHub org/repo and download the **release tarball** for a pinned `ref`
   (`Tiger_Module_Github`); `installFromTarball()` is the shared tail (also for offline/local
   installs + testing — the seam a no-shell web installer will reuse).
2. Extract it (`PharData`, zip-slip guarded) and read `module.json`.
3. Enforce compatibility — `requires.tiger` is checked against `TIGER_VERSION` (refuses a module
   that needs a newer platform).
4. Move it into `application/modules/<slug>/`, run its **migrations** (`Tiger_Db_Migrator`),
   **publish assets** (symlink `public/_modules/<slug>/`), and record a row in the **`module`**
   table (`slug, name, version, repository, ref, source, active, status`).

**The Module Manager (admin).** The `system` module ships a WP-style Module Manager: an **installed
list** (`ModulesController::index` — activate / deactivate, source / license / free-tier badges) and
an **Add New** screen backed by the **Vendor Registry** (`Tiger_Module_Registry` fetches +
TTL-caches `WebTigers/Vendors/index.json`, offline-resilient) plus **Install from URL**. It drives
the same `Tiger_Module_Installer` engine — **no shell, no Composer** (the cPanel-native path).

**Updating a module, today:**
- **In the UI** — in *Add New*, an already-installed module shows an **"Update to vX"** button that
  re-installs at the new ref (a forced install).
- **On the CLI** — re-run `module:install <url> <newer-ref>`.

Both re-extract, re-migrate, re-publish, and update the `module` row. Still to come (BACKLOG): a
first-class `module:update` verb, and **proactive "update available" badges on the *installed*
list** — today the Manager only surfaces an update once you open *Add New* for that module.

**Activation is the tenancy of code:** discovery loads **active managed** modules; a developer's
own *custom* modules stay always-on (managed-vs-custom = Tiger-owned vs app-owned, the same boundary
as `vendor/`). `Tiger_Module_Dependency` warns (never blocks) on missing requirements / dependents.

---

## After any update — the checklist

- `vendor/bin/tiger migrate` — apply new schema (additive-only; safe to re-run).
- Re-publish assets if they changed (`link:assets` for the theme; module assets re-publish on
  activate). Bust caches: opcache, and any build caches (`var/cache/*`, `var/docs-generated`).
- **Warm per server** in a fleet (each box caches independently): hit a page, or use a module's
  rebuild control (e.g. TigerDocs' *Rebuild index* / *Build reference*).

---

## Not built yet (see [BACKLOG.md](BACKLOG.md))

**The target UX — WordPress-simple.** *Login → Admin → Updates:* one screen lists everything with a
pending update (Tiger + TigerCore to latest, and each module), checkbox-select or "Update All",
click → it **self-installs** (download → verify → apply → migrate → warm), no shell, no Composer —
with a **full step-by-step log** streamed live and kept for review, so any failure is diagnosable.
Everything below is the engine behind that one click.

Already built (don't re-scope these): the **Module Manager** (installed list + Add New), the
**Vendor Registry** fetch/cache, and **per-module update via the UI** ("Update to vX"). The gaps:

- **Proactive update detection** — the Manager fetches the registry but the **installed list
  doesn't diff installed-vs-latest**, so there are no "update available" badges there (you only
  see it in *Add New*). No core/Packagist version check yet either. Plus a first-class
  **`module:update <slug>`** CLI verb (CLI update = re-`module:install` today).
- **The unified one-click *Updates* screen** — WP *Dashboard → Updates*: everything stale (core +
  every module) in one place, checkboxes, **Update All**. Today it's one module at a time via Add New.
- **Core / platform self-update** — the Manager updates **modules only**; Tiger + TigerCore still
  need `composer update`. The no-shell path (pre-built vendored release ZIP + a browser updater that
  verifies, stages, and **atomically swaps `vendor/`**) is unbuilt.
- **Full update logs** — the streamed + retained "what broke" audit trail across all of the above.
