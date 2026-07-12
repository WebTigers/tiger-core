# Tiger — ACL: the floor, the maps, and the token

How authorization works in Tiger, and the design for making it **app-configurable and multi-tenant**
without turning it into a footgun. For the enforcement points read [WEBSERVICES.md](WEBSERVICES.md)
(§7) and [ARCHITECTURE.md](ARCHITECTURE.md) (§8); this document is the *authorization model* and the
plan to extend it.

> **Status.** The **platform ACL** (§1) ships today. The **app / tenant maps** and the
> **token-selected context** (§3 onward) are a **design proposal** — the design of record, not yet
> built. Two backlog priorities feed it: stateless `/api` auth (the token) and this. Build to this
> doc; if you change the model, change this doc in the same commit.

---

## 1. What ships today — the platform ACL

`Tiger_Acl_Acl` on top of `Zend_Acl`, and it is deliberately boring:

- **Deny-by-default.** A resource with no matching allow rule is denied. There is no "open by accident."
- **Resource = a class, privilege = an action.** A controller dispatch checks
  `isAllowed($role, <Controller>, <action>)`; an `/api` service checks
  `isAllowed($role, <ServiceClass>, <method>)`. (See WEBSERVICES.md §7.)
- **Role lives on the membership,** not the user — resolved live from `org_user` for the active org
  every request. Same person can be `admin` in one org and `viewer` in another.
- **Data, not code.** The default role graph (guest → user → manager → supermanager → admin →
  superadmin → developer) and all rules live in `acl.ini` (code-shipped, per module) + the `acl_*`
  tables. The engine hardcodes nothing.
- **Enforced at two choke points** — the front-controller `Authorization` plugin (every dispatch)
  and the `/api` gateway (`Tiger_Ajax_ServiceFactory`).

It is one ACL. Every request is judged against the same rule-set. That's the thing we're extending.

---

## 2. The goal

An app developer needs to author their **own** authorization — general app rules *and* per-tenant
rules — **without editing the platform**, and choose **which rule-set applies per request**. Concretely:

- A SaaS wants a **default app policy** on top of the platform's.
- A specific **tenant** wants their own tweaks (tighter, or a custom role graph).
- A **token** (machine, mobile, public API) should be able to say *which* of these it runs under.

So: more than one ACL **map**, and something that **selects** one per request. The token is that
selector — "large and in charge" — but with hard rails, because a configurable authorization system
that can escalate is a vulnerability, and one you can't debug is a support fire.

---

## 3. The layering — a floor, then maps

The single most important rule: **maps never replace the platform ACL. They layer on it.**

```
                    ┌─────────────────────────────────────┐
   request  ─────▶  │  effective decision                 │
                    │                                      │
                    │   selected map   (app or tenant)     │  ← refines WITHIN the floor
                    │   ───────────────────────────────    │
                    │   THE FLOOR      (platform ACL)      │  ← always applies; nothing overrides it
                    └─────────────────────────────────────┘
```

- **The floor is the platform ACL, and it is immovable.** The kernel guards — reserved modules
  (`tiger`/`zend`/`core` are never dispatchable via `/api`), kernel services never public, the
  security invariants — **always apply. No map can punch through them.**
- **A map refines within the floor.** It can *narrow* (a tenant that forbids `refund` for managers)
  or *grant within reach* (allow an app-defined role a resource the floor doesn't already deny at the
  kernel level). It cannot un-gate anything the floor hard-denies.
- **Effective decision = the floor's hard denies win, then the map.** Deny wins over allow, always
  (see §7). A map is icing; the floor is the cake, and you can't scrape the cake off.

Rejected: letting a map *be* the whole ACL (replace mode). One fat-fingered tenant map and someone's
kernel is exposed. Layering is the only safe model.

---

## 4. Maps — named policy sets

A **map** is a complete, named authorization rule-set with a scope:

| Scope | What | `org_id` |
|---|---|---|
| `platform` | the floor — ships in code + `acl_*` | — |
| `app` | the developer's app-wide policy, on top of the floor | null |
| `org` | one tenant's policy | set |

**Storage** rides the existing shape — `acl.ini` + `acl_*` + role-on-membership — plus **one new
dimension**, not a parallel system:

- an **`acl_map`** row: `map_id`, `name`, `slug`, `scope`, `org_id` (nullable), `active`, `description`
  + standard columns;
- rules carry a **`map_id`** (null = the floor). Resources (class names) stay global — they're code.
  The role graph is shared, with app/tenant maps able to add roles that extend it.

Config-discipline holds ([config-discipline]): a real, declared table — not a `wp_options` junk drawer,
and not "shove JSON in a config row."

---

## 5. The token — selector, with one hard rule

The token (from the stateless-auth design — a `personal_access_token` credential factor) carries
**identity + which map**. That's the flexibility. Here is the rail that keeps it from being a
privilege-escalation vector:

> ## A token **narrows**. It never **widens**.
> **Effective grants = (what the identity's role allows) ∩ (what the token's map permits).**

- A **user's** personal token cannot grant more than that user's role already has — it can only
  *scope down* (a read-only token, a token limited to one org, a token for one service).
- A **server / machine** token is a different principal: it has no membership role, so **its map *is*
  its authority** — and precisely because of that, those tokens are **issued and trusted**, never
  user-held, and gated behind `code.execute`/superadmin to mint.

Break this rule and "the token decides the ACL" becomes "any token is admin." Don't break this rule.

---

## 6. Resolution — one place, per request

Authorization is resolved **once, at the gateway**, exactly like identity (see the stateless-auth
resolver):

1. **Identity + context.** `Authorization: Bearer <token>` → token → `user_id` + `map`. Else the
   session identity + the **org's default map** (or the platform default). Token wins if both present.
2. **Build the effective ACL** = floor **composed with** the selected map (cached per `map_id`).
3. **Decide** — the same `isAllowed($role, $resource, $privilege)` runs. **Every service and every
   downstream line of code is map-agnostic** — it never knows which map judged the request. That
   invariance is the whole point: flexibility at the door, simplicity everywhere behind it.

Precedence for *which* map: explicit **token map** > the **org's** default map > the **platform**
default. Deterministic, and logged (§7).

---

## 7. The WHY — explainability is a pillar, not a debug flag

A configurable ACL you can't interrogate is a 3am support call. So the *why* is built in from line one:

- **Every deny is a sentence, not a mystery.** The decision is a structured record:
  ```
  403 · role=manager · resource=Billing_Service_Invoice · privilege=refund
      · map=org:acme · layer=map · decision=DENY (no matching allow → deny-by-default)
  ```
  Returned in the response in **dev**; written to the **log always** (via `Tiger_Log`, structured).
- **An ACL Simulator in the admin.** Pick a **role × resource × privilege × map** and get back the
  **decision, the winning rule, and the layer trace** (floor → map → final). A "would this be allowed,
  and *why*?" box. This one screen is what turns "super flexible" from a liability into a feature.
- **Trace the layering.** Always be able to answer *which layer* decided — the floor, or the map —
  and *which rule* in it (or "no rule; deny-by-default"). Never just `403`.

Deny-by-default is safe but silent; explainability is what makes it *livable*. If someone's locked
out, the system tells them exactly which rule in which map did it.

---

## 8. Invariants & sharp edges (the "carefully")

- **The floor is sacred.** A map can *never* un-gate a reserved kernel resource. Enforce it with a
  **test** that tries and must fail — not just a code comment.
- **Deny wins.** Conflict resolution is deny-over-allow, always. Documented loudly so no one is
  surprised that their tenant's allow didn't beat a floor deny.
- **Fail-closed.** Unknown / missing / inactive map → **deny**, never "fall back to open." A typo in a
  `map` claim locks *out*, not *in*.
- **No escalation.** The §5 narrowing rule is enforced at resolution, not trusted.
- **Cache per map.** The effective ACL is now per-context — build + cache keyed by `map_id`, invalidate
  on rule change (same discipline as the config/docs caches).
- **Reserved names.** Kernel roles/resources can't be redefined by a map.

---

## 9. How it fits what already exists

Nothing here is a rewrite — it's a selector on seams you already have:

- **`Tiger_Acl_Acl`** gains "load a map + compose with the floor"; the `isAllowed` contract is unchanged.
- **`acl_*` + role-on-membership + org-scoped config** — the map is one more scope dimension, the same
  pattern as per-org theming/config.
- **The token** is a `user_credential` factor (`personal_access_token`) — the 1-to-many factor table
  absorbs it with no schema change (stateless-auth design).
- **The gateway + Authorization plugin** stay the enforcement points; they gain identity-and-map
  resolution, not new choke points.

---

## 10. Build order

Debuggability **first** — so you can see the machine think while you build it.

1. **The *explain* trace + ACL Simulator — ✅ built.** `Tiger_Acl_Acl::explain($role, $resource,
   $privilege)` returns the decision + the deciding rule (inheritance-aware) or deny-by-default + the
   role chain; the admin **ACL Simulator** (`/system/acl`, `System_Service_Acl`) runs it live. This
   works on the current single (floor) ACL today — the "why am I locked out?" answer exists *before*
   maps, exactly as intended.
2. **Maps + composition** — the `acl_map` table, floor+map composition (floor immovable, deny-wins);
   extend `explain()` to name which *layer* (floor | map) decided. *(Next.)*
3. **Token → map selection** — the token (stateless auth, #3 — built) carries a `map`/org context;
   wire it into §6, enforce §5 narrowing. *(Next.)*
4. **Per-tenant map authoring** (admin) + the reserved-floor test.

---

*Authorization is the one place "flexible" and "safe" pull in opposite directions. The floor keeps it
safe; the maps keep it flexible; the token chooses; and the explain trace keeps it debuggable. Build
in that order and you never ship the 3am lockout.*
