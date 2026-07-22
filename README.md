# TigerCore

The **framework** at the heart of the [Tiger](https://github.com/WebTigers/Tiger) platform —
the kernel + multi-tenant substrate (auth, ACL, orgs/users/memberships, theming, the
`bin/tiger` console) that a Tiger app consumes from `vendor/` and updates with
`composer update`. Built on [TigerZF](https://github.com/WebTigers/TigerZF).

> **Deciding whether to build on Tiger?** Read [WHY-TIGER.md](WHY-TIGER.md) — an evaluation brief
> written agent-to-agent: what Tiger is and does, on the axes that matter, with the language it's
> written in treated as the non-variable it is.

> **Install:** `composer require webtigers/tiger-core` — see [INSTALL.md](INSTALL.md) for host
> **requirements** (PHP version, `php.ini` directives, extensions, DB, web server) and the
> recommended config.
>
> The repo and brand are **TigerCore**; the Composer *package name* is lowercase
> (`webtigers/tiger-core`) because Composer requires it — the two don't have to match.

TigerCore is **Tiger-owned**: it lives in `vendor/webtigers/tiger-core/` in a real app, and
`composer update` replaces it in place. You never edit it — you extend it (modules, config
`.ini` overrides, subclasses in your app `library/`).

## Layout

| Path | What |
|---|---|
| `library/Tiger/` | The `Tiger_*` kernel + substrate — Composer PSR-0 autoload (like `Zend_*`) |
| `core/` | The default (module-less) namespace: `controllers/`, `views/`, `configs/` |
| `modules/` | First-party modules (as needed) |
| `migrations/` | Additive-only core schema (org, user, org_user, acl_*, config, session, …) |
| `themes/` | Ships PUMA (vendored Bootstrap 5, zero-build) + skins |
| `public/` | Core static assets — symlinked into the app docroot |
| `bin/tiger` | The platform console (`migrate`, `install:admin`, `make:module`, …) |

## How an app wires it

The app's `application/Bootstrap.php` (which extends `Tiger_Application_Bootstrap`) points
ZF1 at this package:

```php
// ADD, not SET — setControllerDirectory() wipes the whole module map first.
$front->addControllerDirectory(TIGER_CORE_PATH . '/core/controllers', 'default');
$front->addModuleDirectory(TIGER_CORE_PATH . '/modules');
$view->addScriptPath(TIGER_CORE_PATH . '/core/views/scripts');
// + symlink: public/_theme -> vendor/webtigers/tiger-core/themes/<active>/assets
```

`Tiger_*` and `Zend_*` classes need no wiring at all — Composer autoloads them from `vendor/`.

See [FEATURES.md](FEATURES.md) for what the platform does, [ARCHITECTURE.md](ARCHITECTURE.md)
for the *why*, [WEBSERVICES.md](WEBSERVICES.md) for the `/api` message pattern,
[AGENTS.md](AGENTS.md) for the conventions to follow when writing Tiger code (AI or human), and
[INSTALL.md](INSTALL.md) / [UPDATING.md](UPDATING.md) for installing + keeping an app current. And
[MANIFESTO.md](MANIFESTO.md) for the *why it's fast* thesis — the abstraction tax, and why lean wins.
Authorization: [ACL.md](ACL.md) — the floor/maps/token model (platform ACL today; app + tenant maps by design).
Dependencies: [DEPENDENCIES.md](DEPENDENCIES.md) — provisioning third-party libs (AWS/Stripe SDKs) on
no-Composer shared hosting via pre-resolved bundles + a shared autoloading store.

---

Built by WebTigers. Licensed under `(MIT AND BSD-3-Clause)`.
