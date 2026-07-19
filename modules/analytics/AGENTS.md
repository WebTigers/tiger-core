# AGENTS.md — working on the `analytics` module

Instructions for an AI assistant (or a new contributor) working on Tiger's **analytics** module. For
platform conventions read the root **AGENTS.md** (`read.guide` with no module). This file is the
module-specific layer; match the surrounding style.

> First-party **Google Analytics (GA4)** — the WordPress-parity "paste your tracking ID" feature done
> the Tiger way: config-backed (no deploy), consent-aware (`Tiger_Consent`), provider-extensible
> (`Tiger_Tracking`). It emits gtag.js on public pages AND offers in-app GA4 reporting (OAuth connect,
> a reports dashboard, a "Traffic" dashboard widget). This module owns only GA; the cookie banner is a
> separate feature.

## The one thing to know: two distinct GA surfaces

1. **Tagging** — the client-side gtag.js snippet; needs only a `measurement_id`, **no OAuth**.
2. **Reporting** — server-side GA4 Data API pulls (dashboard + widget); needs an OAuth *connection* via
   `Tiger_Google_Analytics`.

A site can tag without reporting. Don't conflate them.

## Where things live (no models of its own)

- `controllers/AdminController.php` — `/analytics/admin` (settings), `/connect`, `/callback`,
  `/disconnect`, `/dashboard` (all admin). OAuth state in a `Zend_Session_Namespace`.
- `services/` — `Analytics` (settings), `Reports` (read-only). `forms/Settings.php`.
- `plugins/Tag.php` (the gtag emitter), `widgets/Ga.php` (the dashboard card).
- **No `models/`** — `Tiger_Model_Config` + `Tiger_Google_Analytics` (OAuth/reporting engine) +
  `Tiger_Consent`/`Tiger_Tracking`/`Tiger_Dashboard`.

## The `/api` surface (admin)

- **`Analytics_Service_Analytics`** — `save`: write `tiger.analytics.enabled` /
  `.ga4.measurement_id` / `.exclude_signed_in` (config, GLOBAL) + (guarded) the reporting mode + OAuth
  creds + property id via `Tiger_Google_Analytics::saveMode()`/`saveOauthConfig()`.
- **`Analytics_Service_Reports`** (read-only) — `summary` (file-cached GA4 traffic for a window;
  `fresh` bypasses cache; `analytics.reports.not_connected` when unconnected), `test` (connection
  self-test — **note: a failed connection is still `result=1`; the client reads `data.test.ok`**).

## Conventions + gotchas (this module)

- **Broker vs BYO OAuth.** *Broker* mode uses the WebTigers-hosted broker with **PKCE** (verifier stays
  server-side; only the challenge + `?handoff` travel) and needs just a GA4 property id. *BYO* mode
  runs Google's flow against the operator's own client id/secret (secret encrypted; blank on save keeps
  the existing one) with a `state` nonce checked via `hash_equals`. Redirect URI is always
  `/analytics/admin/callback`.
- **The tag rides a front-controller plugin.** `Analytics_Plugin_Tag` (stackIndex **92**,
  `preDispatch`, emit-once) appends gtag.js to the `tigerTracking` head placeholder — which **only the
  public layout renders**, so admin traffic is never tagged. It emits only when enabled + a valid
  `G-XXXX` id + `Tiger_Consent::allows('analytics')` (TRUE until the cookie feature sets a mode, so GA
  works standalone) + not excluding signed-in staff (`exclude_signed_in` default on). **Fail-open.**
- **Reporting is read-only + cached** — `summary()` is file-cached; the `Analytics_Widget_Ga` dashboard
  card fetches it **async over `/api`** (days=28) so a cold GA call never blocks the dashboard render;
  shows a "Connect Google Analytics" prompt when unconnected.
- **The two switches (`enabled`, `exclude_signed_in`) are NOT form elements** — the form validates only
  `ga4_measurement_id` (`^G-[A-Z0-9]{4,}$`); the switches + OAuth fields come straight off `$params`.
- **Config scope is GLOBAL today**, isolated in `_scope()` (multi-site flips to per-org later).
- **Everything is `class_exists`/`method_exists`-guarded** against a Core predating
  `Tiger_Tracking`/`Tiger_Dashboard`/`Tiger_Google_Analytics` — the module still loads (degraded) on
  older cores.
- **Registration:** `Bootstrap` registers the tag plugin, the tracker (`Tiger_Tracking::register`), the
  Traffic widget (`Tiger_Dashboard::registerWidget`), a Settings-tree entry, AND a top-level nav item
  via `configs/navigation.ini` (`/analytics/admin/dashboard`).

## ACL

admin: `Analytics_AdminController` (covers connect/callback/disconnect/dashboard),
`Analytics_Service_Analytics`, `Analytics_Service_Reports`.

## Do / Don't

- **Do** keep tagging consent-gated + fail-open; **do** fetch reports async so the dashboard never
  blocks.
- **Don't** treat `test()`'s `result=1` as "connected" — read `data.test.ok`.
- **Don't** emit the tag into admin/auth layouts (they don't render `tigerTracking` — keep it that way).
