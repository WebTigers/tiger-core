# AGENTS.md — working on the `identity` module

Instructions for an AI assistant (or a new contributor) working on Tiger's **identity** module. For
platform conventions read the root **AGENTS.md** (`read.guide` with no module). This file is the
module-specific layer; match the surrounding style.

> "Site Identity" — sets site name, tagline, logo, favicon, and social profile URLs, all stored in the
> **config tier** (live-override, no deploy). Its values are plain `tiger.site.*` / `tiger.seo.social.*`
> config consumed by the layout (favicon), TigerSEO (Organization `logo` + `sameAs`), and anywhere
> those keys are read.

## The one thing to know: it's config, not a table

Every field is a `Tiger_Model_Config` write, not a settings row — the live-override tier, no deploy.
Logo/favicon are stored as **media UUIDs** (validated), not files.

## Where things live (no models, no routes)

- `controllers/AdminController.php` — `/identity/admin` (admin). Reads live config, populates the form,
  passes `logoId`/`faviconId` to the media-picker view.
- `services/Identity.php`. `forms/Identity.php`. `plugins/Favicon.php`.
- **No `models/`** — writes `Tiger_Model_Config`, reads `Tiger_Model_Media`. No `routes.ini`.

## The `/api` surface (admin)

- **`Identity_Service_Identity`** — `save`: validate the form → transaction → write every `KEYS` entry
  + `tiger.site.logo`/`tiger.site.favicon` (each a 36-char media UUID or `''`) to config. `KEYS` maps
  form field → config key (`site_name→tiger.site.name`, `social_twitter→tiger.seo.social.twitter`, …).

## Conventions + gotchas (this module)

- **`_scope()` is a deliberate override seam.** It returns `[SCOPE_GLOBAL, '']` today; a future
  multi-site module flips identity to per-org **without touching the rest**. Guests only receive GLOBAL
  config by design — that's why identity is global for now.
- **Logo + favicon are NOT form elements** — they're media references the view renders with the
  `mediaField()` helper; their hidden inputs post with the form and the service reads them from
  `$params` (validated to a UUID or cleared).
- **The favicon rides a front-controller plugin.** `Identity_Plugin_Favicon` (stackIndex **91**,
  `preDispatch`, emit-once latch) reads `tiger.site.favicon`, resolves it via `Tiger_Model_Media`, and
  emits `headLink` `rel="icon"` + `apple-touch-icon`. **Fail-open** — any error emits nothing.
- **Social URLs use a lenient regex** (`^https?://.+`) that only runs when a value is present — ZF1
  ships no URI validator, and these are all optional.
- **Registration:** `Bootstrap::_initAdminSettings()` adds a Settings-tree entry (key `identity`, icon
  `fa-fingerprint`, order 10, resource `Identity_AdminController`).

## ACL

admin: `Identity_AdminController`, `Identity_Service_Identity`. (The dedicated resources are the seam
for later letting an org admin manage that org's identity.)

## Do / Don't

- **Do** keep settings in `config` (config-discipline), and keep `_scope()` the single override point.
- **Do** validate media references to a UUID before storing.
- **Don't** add a settings table; **don't** make the favicon plugin fail hard (it must fail-open).
