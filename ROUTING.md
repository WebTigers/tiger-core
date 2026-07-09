# Tiger — Routing & module route overrides

How URLs reach code in Tiger, and the one right way to give a module a pretty public route.
Read this before adding a route. For the *why* behind the platform read
[ARCHITECTURE.md](ARCHITECTURE.md); for conventions read [AGENTS.md](AGENTS.md).

## 1. Convention first — you rarely need a route

Every module surface is already reachable at its **canonical MVC path** via ZF1's built-in
`:module/:controller/:action` route — **with zero registration**:

```
Docs_IndexController::docsAction      ->  /docs/index/docs
Docs_AdminController::settingsAction  ->  /docs/admin/settings
Billing_InvoiceController::viewAction ->  /billing/invoice/view/id/42
```

**Do not register a route for the canonical path.** It exists for free, it's stable, and it's
what everything else points at. Trailing `key/value` pairs fold into `getParam()` automatically
(`/billing/invoice/view/id/42` → `id=42`). No query strings for navigation (see AGENTS.md).

## 2. Pretty routes are *overrides*, never hand-added `addRoute` calls

A vanity URL like `/docs` (instead of `/docs/index/docs`) is an **optional alias**. Declare it —
don't imperatively `$router->addRoute()` in your module Bootstrap. Declaring puts every alias
through one ordering authority; hand-adding routes scatters precedence across bootstraps and
makes you fight Zend's route stack.

A module declares its default alias from its Bootstrap:

```php
protected function _initRouteOverride()
{
    Tiger_Routing_Overrides::register('docs', [
        'pattern'  => 'docs',            // public path prefix; the remainder becomes the `slug` param
        'target'   => 'docs/index/docs', // the canonical module/controller/action it maps to
        'priority' => 100,               // higher = checked first when two prefixes overlap
    ]);
}
```

That's the whole contract: **a public prefix maps to a canonical MVC target, and the remaining
path (possibly nested) arrives as `slug`.** `/docs` → the target with no slug; `/docs/guides/deploy`
→ the target with `slug = guides/deploy`.

## 3. The admin overrides a declaration by name — in `config`, no deploy

Overrides live in the **`config` DB tier** (dot-notation, `tiger.routing.override.<name>.*`) — not
a new table (config-discipline: the config store, never a wp_options-style side table). Any field
wins over the module default, effective next request:

```
tiger.routing.override.docs.pattern  = "help"   ; serve the docs at /help instead
tiger.routing.override.docs.enabled  = 0        ; turn the pretty route off (canonical path still works)
tiger.routing.override.docs.priority = 250      ; reorder against another module's overlapping alias
```

A module ships a settings screen (`<module>/admin/settings`) that writes these keys via
`Tiger_Model_Config` — see `Docs_AdminController` + `Docs_Service_Settings` for the reference.

## 4. How it's applied — and why LIFO stops mattering

`Tiger_Controller_Plugin_RouteOverride` runs at **`routeShutdown`** (after routing, before
dispatch). For each request it:

1. **Bails if a real controller already claims the URL** (`isDispatchable`). This one guard is
   why a pretty `/docs` alias never shadows the module's own `/docs/admin/settings`, and why the
   reserved kernel prefixes `/api`, `/auth`, `/admin` are safe.
2. Otherwise walks `Tiger_Routing_Overrides::all()` — **sorted by priority DESC** — and rewrites
   the request to the **first** matching prefix's target, with the remainder as `slug`.

Because the plugin decides order itself, overrides are **immune to Zend's last-in-first-out route
matching**. You still add real `addRoute` routes for genuinely custom URL shapes (the `/api`
gateway does), and those *are* LIFO — last added is matched first — but you never need that
machinery for a module's public alias.

```
Router stack (LIFO, last added wins):   custom addRoute routes  ->  /api  ->  default :m/:c/:a
routeShutdown plugins (in order):       RouteOverride (pretty aliases)  ->  PageDispatch (CMS pages)
```

## 5. Precedence, end to end

For any incoming URL:

1. **A real controller?** It wins (canonical MVC paths, `/api`, `/auth`, `/admin`, a module's own
   controllers). Overrides never touch it.
2. **A declared override prefix?** `RouteOverride` rewrites it to the canonical target (highest
   priority first).
3. **A published CMS `page` / `page_redirect`?** `PageDispatch` serves it (or 301s).
4. **Nothing?** A clean 404.

## 6. Rules of thumb

- Reach features at `<module>/<controller>/<action>`; don't register the canonical path.
- Want a pretty URL? **Declare** an override; never `addRoute` an alias in a module Bootstrap.
- **A module never touches web-server config.** Pretty routes are PHP-layer overrides that work
  the instant a module is activated — editing Apache/nginx to route a module is *never* a module's
  job (it would break 1-click install). Changing a deployment's own web-server config is the
  app/SaaS owner's prerogative, separate from installing a module.
- Priority is **open** — a module may declare any weight, even one that outranks core. Open is
  open; band-clamping guardrails can be added later if abuse ever appears. Keep module defaults
  in a sane range (≈100) so an admin rarely has to reorder.
- Reserved prefixes (`api`, `auth`, `admin`) can never be claimed by an override.
- A doc/content slug that collides with a real controller name under the same prefix is shadowed
  by the controller (it's dispatchable) — don't name content after a controller.
