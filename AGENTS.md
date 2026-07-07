# AGENTS.md — writing code in Tiger

Instructions for an AI assistant (or a new human contributor) working in this codebase.
Follow the patterns already here; don't invent new ones. For the *why* read
[ARCHITECTURE.md](ARCHITECTURE.md); for the feature surface read [FEATURES.md](FEATURES.md);
for the `/api` model read [WEBSERVICES.md](WEBSERVICES.md).

> This file documents TigerCore + app conventions. Tiger is designed to be read and written
> largely by AI — so the docs live in the code. Match the surrounding style.

## The cardinal rule: extend, don't edit

`vendor/` is Tiger-owned and replaced by `composer update`. **Never edit framework files to
change app behavior** — you'll lose it on the next update. Extend instead:

- Add `_init*` methods in the app's `Bootstrap` (which extends `Tiger_Application_Bootstrap`).
- Subclass (`App_Service_Base extends Tiger_Service_Service`), override config `.ini`, or add
  a module — don't patch `Tiger_*`.
- `@api` = stable to build on; `@internal` = may change.

## The four layers

`Zend_*` (engine, in TigerZF) · `Tiger_*` (platform) · `App_*` (app-shared, route-less) ·
**modules** (features with routes/UI). A feature with controllers/routes/ACL/views is a
**module**; shared plumbing with no routes is the **app library**.

## Code conventions

- **Short arrays `[]`, never `array()`** — in TigerCore and app code. (TigerZF keeps `array()`
  to match upstream ZF1; don't touch it.)
- **Docblock every class and non-trivial method**, explaining intent, not mechanics. Comment
  density here is higher than typical — keep it.
- Constants for magic values; guard clauses over deep nesting; `Throwable` in catches.
- **No URL query params for navigation.** Use ZF1 path-style params — `/auth/login/out/1`, not
  `?out=1` (the `:controller/:action/*` route folds trailing pairs into `getParam()`); tokenized
  links are path-style too. A return/intended-destination path lives in the **session**, never the
  URL (see `Tiger_Service_Authentication::setReturnTo`/`takeReturnTo`) — avoids `%2F` path 404s and
  history leakage.

## Client/server: the UI calls `/api`, it does not post to pages

Tiger apps are **client/server**. The server renders the initial HTML — the page *shell* and
public CMS page bodies — and from there **the browser is a client that exchanges data with
`/api` over AJAX**. It does **not** submit `<form>` page-POSTs to a controller and re-render the
whole page. Every dynamic interaction is a Tiger Webservice call:

- **Auth & session** — login, logout, lock-screen unlock, signup, password reset are AJAX. (Auth
  is a *reserved* kernel service, so these post to a thin controller that returns JSON — e.g.
  `/auth/login` — rather than literal `/api`; it's the same AJAX-JSON contract. See
  WEBSERVICES.md §8.)
- **Data in** — every insert / update / delete is an `/api` service call (validate → transaction);
  the JSON response drives the client (redirect, inline field errors, toast).
- **Data out** — list / table data (DataTables, Select2, autocompletes) is **fetched from `/api`**,
  not server-rendered into rows. DataTables loads its rows over AJAX and the service returns the
  DataTables shape (`{data:[…]}` for an ajax source, or `{draw, recordsTotal, recordsFiltered,
  data}` for server-side processing). See WEBSERVICES.md §5.

**SSR is for the shell, not the data.** A controller action renders the initial screen and then
gets out of the way; the logic and the data live in services. The payoff is the front-end-agnostic
contract (FEATURES.md): the same `/api` feeds the PUMA SSR theme, a future SPA theme, or a mobile
client, because the UI is always just a client.

## Services (`/api`) — the canonical flow

Services extend `Tiger_Service_Service`; the message's `method` names the action, which
receives the whole `$params`. **Always validate a form first, then wrap mutations in a
transaction:**

```php
public function create(array $params): void
{
    if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

    $form = new Billing_Form_Invoice();
    if (!$form->isValid($params)) { $this->_formErrors($form); return; }   // isValidPartial() for PATCH

    try {
        $id = $this->_transaction(function ($db) use ($params) {
            // ... inserts/updates; throw to abort + roll back ...
            return $newId;
        });
        $this->_success(['id' => $id], 'billing.invoice.created');
    } catch (Throwable $e) {
        $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
    }
}
```

Never emit business errors as bare strings or raw exceptions — use `_error`/`_formErrors`
with a translation key. Never put business logic in a controller; controllers are thin.

## Forms

Extend `Tiger_Form` (not `Zend_Form` directly). Declare elements as an array schema via
`elements()` (array config, not `.ini`); the base handles POST method, ViewHelper-only
decorators (the view owns markup), CSRF, and the `_t()` translate helper.

```php
class Billing_Form_Invoice extends Tiger_Form
{
    protected function elements(): array
    {
        return [
            ['text', 'amount', [
                'required'   => true,
                'filters'    => ['StringTrim'],
                'validators' => [['Float']],
                'attribs'    => ['class' => 'form-control', 'placeholder' => $this->_t('billing.invoice.amount')],
            ]],
        ];
    }
}
```

## Models & schema

- Extend `Tiger_Model_Table`. It mints the UUID PK on insert (v7 default; set
  `$_uuidVersion = 4` for opaque tokens), stamps timestamps + `created_by`/`updated_by`, and
  soft-deletes. Domain finders build on `activeSelect()` (excludes deleted).
- **Build every query with the query builder — never a raw SQL string.** `activeSelect()` /
  `$db->select()->from(...)` + bound `where('col = ?', $v)`, and `new Zend_Db_Expr('COUNT(*)')` for
  aggregates. Parameterized, portable, injection-safe. No `$db->query("SELECT …")` /
  string-concatenated SQL in models or services.
- Every domain table gets the **standard columns**: `status`, `deleted`, `created_by`,
  `updated_by`, `created_at`, `updated_at`. UUIDs are `CHAR(36)`; unique text is `VARCHAR(191)`.
- Migrations are **additive-only** PHP files (`NNNN_name.php` returning `['up'=>[], 'down'=>[]]`)
  in `migrations/`. One logical DDL change per migration (MySQL auto-commits DDL).

## Authorization

Deny-by-default. Access is data in `acl.ini` / `acl_*` tables, never a hardcoded role compare.
A resource with no allow rule is denied. Role comes from `org_user` (role-on-membership) and
is resolved live each request. Module `configs/acl.ini` grants access to that module's
controllers/services.

## Internationalization

- **Never hardcode user-facing strings.** Use a semantic, **owner-prefixed** key: `core.*`
  (framework), `app.*` (app), `<module>.*` (module), then `.area.type.name` —
  e.g. `billing.invoice.created`, `core.api.error.not_allowed`.
- Put strings in PHP array files: `<module>/languages/<lang>/<module>.php` returning
  `['key' => 'text']`. Locales are **language-only** (`en`, `es`).
- Response messages translate automatically (`Tiger_Model_MessageObject`). In views/forms use
  `$this->_t('key')`.

## The live-override pattern (reuse it)

Config and translations both follow: **files/ini = base, a DB table = the runtime override
tier (last wins), no deploy.** When you build a new "options/settings" surface, mirror this —
a `*_key`/`*_value` table with `scope` (global|org) + `scope_id`, loaded on top of the base in
a bootstrap `_init*`. See `Tiger_Model_Config` / `Tiger_Model_Translation`.

## Scaffolding & housekeeping

- New feature → `vendor/bin/tiger make:module <name>` (gives a live controller + `/api`
  service + ACL + views + config to build from).
- Keep secrets out of the repo — DB creds and keys go in `local.ini` (gitignored) or a secrets
  manager, never in `application.ini` or committed files.

## Anti-patterns (don't)

- Don't edit anything under `vendor/`.
- Don't use `array()` in TigerCore/app code.
- Don't hardcode user-facing strings, roles, or config — use keys, ACL data, and config.
- Don't put logic in controllers, or run mutations without a form-validate + transaction.
- Don't build REST-by-URL endpoints — use the `/api` message pattern.
- Don't page-POST forms to controllers or server-render list/table data — the UI is a client
  that calls `/api`; controllers render the initial shell only (see the client/server section).
