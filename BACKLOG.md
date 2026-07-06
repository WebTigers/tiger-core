# Tiger — Backlog

A manual, lightweight tracker for planned features and known issues (in lieu of GitHub issues
for now).

**Workflow:** when an item ships, **delete it from this file**. If it's a user-facing
capability, add it to [FEATURES.md](FEATURES.md). Keep this list short and current — it's a
working to-do, not a changelog (git history is the changelog).

## Features (planned)

- **Core CMS module** *(building this week)* — a first-party module for DB-driven page content,
  so anyone can build a module that renders pages. The vision: a better-architected WordPress.
  - **`page` table** holds **layouts, partials, and pages** (a `type` column) — plus slug/route,
    title, body/template, a layout reference, status, and the standard columns. Multi-tenant
    (per-org) and i18n-aware where it fits; live-editable via the DB-override pattern.
  - **Non-file rendering** — `Zend_View::render()` takes a script *file*; DB-stored templates
    need string / non-file rendering. Add that capability (likely a `Tiger_View` extension, or a
    `Zend_View` enhancement in TigerZF since it's generic engine behavior). Cache compiled output.
  - **Security (the WordPress footgun)** — DB templates are code. Restrict authoring to trusted
    admins and/or use a safe, limited template syntax (never raw `eval` of arbitrary PHP). Design
    this in from the start, not after.
  - **Extensible** — modules register renderable content/types, so the CMS is a rendering
    *substrate*, not a monolith.

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
