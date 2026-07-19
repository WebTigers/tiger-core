# AGENTS.md — working on the `access` module

Instructions for an AI assistant (or a new contributor) working on Tiger's **access** module. For
platform conventions read the root **AGENTS.md** (`read.guide` with no module). This file is the
module-specific layer; match the surrounding style.

> The admin CRUD for the identity + tenancy substrate: **Users** (thin identities) and
> **Organizations** (tenants). One module because the domain is tightly coupled through `org_user`
> membership. (`access` is in the PROTECTED set — it manages user admin, so it can't be deactivated.)

## The one thing to know: it owns UI, not data

No models, no tables. It's admin CRUD over the core substrate — `Tiger_Model_User` (the `user` table)
and `Tiger_Model_Org` (the `org` table). To *extend* a user/org you write an Account module with
FK-linked tables — **never add columns to `user`/`org`**.

## Where things live (no models, no config, no nav)

- `controllers/UserController.php` — `/access/user` (list) + `/access/user/edit/id/<id>` (admin).
- `controllers/OrgController.php` — `/access/org` + `/access/org/edit/id/<id>` (admin).
- `services/` — `User`, `Org`. `forms/` — `User`, `Org`.
- **Empty Bootstrap** — Users/Orgs are top-level admin screens, not Settings-tree entries; no
  `navigation.ini`, no `routes.ini`.

## The `/api` surface (admin)

- **`Access_Service_User`** — `datatable` (identity + membership summary; per-row `can_edit`/
  `can_delete`, `can_delete=false` for your own row), `save` (email + username uniqueness via
  `Tiger_Model_User::isTaken()` with exclude-self), `delete` (refuses your own account).
- **`Access_Service_Org`** — `datatable` (org + parent + member count), `save` (slugify, reject
  empty/self-parent/taken slug via `slugTaken()`), `delete` (refuses the org you're acting in). **v1:
  soft-delete does NOT reparent children or purge memberships.**

## Conventions + gotchas (this module)

- **Role lives on `org_user` membership, never on the user.** The user form has **no role field** —
  the datatable *summarizes* roles across memberships (`GROUP_CONCAT(DISTINCT ou.role)`). A user can be
  admin in one org and viewer in another.
- **A user is pure identity** — email/username/status, **no password**. Credentials live in
  `user_credential` (separate), so there's no password field here.
- **Tenancy boundary = the `org_user` row's existence**, and "the org/user you're acting in" is
  protected: `Org::delete` refuses `$this->_org_id`, `User::delete` refuses `$this->_user_id`. Per-row
  `can_delete` is computed **server-side** (`is_self`/`isCurrent`) — the client only draws off it;
  authorization never lives in the client.
- **Uniqueness is enforced in the *service* here** (`isTaken`/`slugTaken` with an exclude-self id) —
  because admin *edit* needs exclude-self logic a static form validator can't express (contrast
  `signup`, which is insert-only and puts `NoRecordExists` validators on the form). Both back the DB
  unique index with friendly errors.

## ACL

admin (blanket): `Access_UserController`, `Access_OrgController`, `Access_Service_User`,
`Access_Service_Org`.

## Do / Don't

- **Do** extend users/orgs via a new module + FK tables, never new columns.
- **Do** compute delete/edit permission server-side and expose it as a per-row flag.
- **Don't** add a role field to the user (role is a membership concept).
