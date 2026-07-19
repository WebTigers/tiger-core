# AGENTS.md — working on the `signup` module (the reference form)

Instructions for an AI assistant (or a new contributor) working on Tiger's **signup** module. For
platform conventions read the root **AGENTS.md** (`read.guide` with no module). This file is the
module-specific layer; match the surrounding style.

> Public account creation. `Signup_Form_Signup` is the platform's **gold-standard reference form** —
> copy it. The service transactionally creates the whole org/user/membership/address/contact tenant
> graph, then emails a verification link.

## The one thing to know: this is the form to copy

When you build any non-trivial form, start from `Signup_Form_Signup` + its view — it demonstrates every
pattern (DB-uniqueness, password policy + strength meter, address autocomplete, localized country
picker). Match it rather than inventing.

## Where things live (no models, no config)

- `controllers/IndexController.php` — public (guest), switches to the `auth` layout. `/signup` (form)
  + `/signup/index/verify/cid/<id>/code/<token>` (path-style params, no query strings).
- `services/Signup.php`. `forms/Signup.php`. `views/scripts/index/` — the copy-me template.
- **No `models/`** — consumes a wide slice of the substrate. Empty Bootstrap, no `routes.ini`.

## The `/api` surface (guest)

- **`Signup_Service_Signup`** — `create`: validate the form → one `_transaction()` building the tenant
  graph → email verification. No in-service `_isAdmin` gate (the `/api` factory already authorized
  guest). Plus `verifyEmail($cid, $code)` (redeems the `email_verify` challenge, flips the user to
  `active`).

**The create graph (transaction order):** `org` (unique slug) → `user` (**status `pending`**) →
`user_credential` password (`UserCredential::setPassword()`) → `org_user` membership (**role `user`**)
→ `address` linked via `org_address` + `user_address` → `contact` (phone) via `org_contact` +
`user_contact`.

## Conventions + gotchas (this module)

- **New signups are born `status='pending'` and stay effectively guest until verification.**
  `Authentication::login` rejects non-active users; redeeming the `email_verify` `auth_challenge` (TTL
  24h, single-use) in `verifyEmail` is what flips `status` to `active`. Don't assume a fresh signup can
  log in. (Access-admin-created users default to `active` immediately.)
- **The reference-form patterns to copy exactly:**
  1. **DB-uniqueness** — `Zend_Validate_Db_NoRecordExists` on `user.username`/`user.email` as validator
     objects with custom messages (runs on blur AND at submit — no hand-rolled AJAX checks).
  2. **Password** — `Tiger_Validate_Password()` + `data-tiger-strength=1` (meter owns blur UX) +
     `data-no-validate=1` (skip convenience-validate; server policy still runs at submit).
  3. **Email** — `EmailAddress` with `Hostname::ALLOW_ALL` (format-only) + uniqueness as a *separate*
     validator.
  4. **Country** — `registerInArrayValidator = false` + hand-rendered `Tiger_I18n_Country::grouped()`
     optgroup select ("Frequent"/"All countries") with an explicit `InArray` and `data-tiger-ip-country`
     pre-select.
  5. **Address autocomplete** — `data-tiger-address=1` + `data-fill-{city,region,postal,country}` id
     maps (the Location Service fills the mapped fields on suggestion pick).
- **The view wiring:** `<form data-tiger-validate data-module="signup" data-form="Signup">` enables
  convenience validation; each field in a `[data-field]` wrapper for inline `.invalid-feedback`; submit
  via `TigerButton.run` → POST `/api` → `TigerDOM.notify` + `res.form` field errors.
- **Path-style params only** — verify is `/cid/<id>/code/<token>`, never a query string.
- Mail failure in `_sendVerification` is logged, **not fatal** — the account still exists.

## ACL

guest: `Signup_IndexController`, `Signup_Service_Signup`. (Convenience validation rides
`Tiger_Service_Validate`, allowed guest in core.)

## Do / Don't

- **Do** copy this form's validator + view patterns for any new form.
- **Do** treat a new signup as unverified until the challenge is redeemed.
- **Don't** page-POST the form — it's an `/api` `create` call like everything else.
