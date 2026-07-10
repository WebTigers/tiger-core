# Tiger — Updating (platform, core, modules)

How you keep a Tiger install current, layer by layer. This documents the mechanics that **exist
today**; for what's still coming (version-change detection, a Modules admin UI, the no-shell core
self-updater) see [BACKLOG.md](BACKLOG.md). For host requirements read [INSTALL.md](INSTALL.md);
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
become `application/modules/<slug>/`. `Tiger_Module_Installer` does the work; the `bin/tiger`
console is the front-end (a web UI is planned):

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

**Updating a module, today,** is re-running `module:install <url> <newer-ref>` — it re-extracts at
the new ref, re-migrates, re-publishes, and updates the `module` row. (A dedicated `module:update`
verb and "update available" detection are planned — BACKLOG.)

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
click → it **self-installs** (download → verify → apply → migrate → warm), no shell, no Composer.
Everything below is the engine behind that one click.

- **Version-change detection** — polling a registry / Packagist / GitHub tags to surface
  "update available" badges for core *and* modules.
- **`module:update <slug>`** as a first-class verb, and the **Modules admin screen** (WP-style
  list + update badges + Install/Update buttons + registry search) over the existing installer.
- **No-shell / cPanel self-update** — a pre-built vendored release ZIP + a browser updater that
  verifies, stages, and **atomically swaps `vendor/`** (core), and a web front-end over
  `Tiger_Module_Installer` (modules).
