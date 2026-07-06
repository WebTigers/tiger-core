# Tiger — Backlog

A manual, lightweight tracker for planned features and known issues (in lieu of GitHub issues
for now).

**Workflow:** when an item ships, **delete it from this file**. If it's a user-facing
capability, add it to [FEATURES.md](FEATURES.md). Keep this list short and current — it's a
working to-do, not a changelog (git history is the changelog).

## Features (planned)

- **Public site header + landing** — the public side currently has only the floating skin
  switcher; it needs real chrome (header/nav) and a landing page. (Theme-level.)
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
- **Admin shell CDN assets** — Font Awesome + Google Fonts load from a CDN in the admin layout;
  vendor them for production/offline use.
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
