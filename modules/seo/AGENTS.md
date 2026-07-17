# AGENTS.md — writing code in TigerSEO

Instructions for an AI assistant (or a new human contributor) building this module. **TigerSEO is a
Tiger module first**, so the platform conventions win by default.

**Read these first, and follow them over anything here:**
`tiger-core/AGENTS.md` (house conventions — short arrays, docblocks, i18n keys, the `/api`
validate→transaction flow, no page-POSTs) · `tiger-core/ADMIN.md` (the admin-screen template — copy
it; don't invent a shell) · `tiger-core/ROUTING.md` (declare route overrides; never `addRoute` an
alias) · `tiger-core/WEBSERVICES.md` (the `/api` message pattern).

This file covers only what's **different** because this is SEO. For the *why* behind everything below
read [ARCHITECTURE.md](ARCHITECTURE.md) — and if you're about to make it cleverer, read §10 first.
Most "obvious improvements" here are things we already said no to, with reasons.

## The five rules that are not negotiable

1. **The head registry is core's, not ours.** Contribute through `$this->headTitle()` /
   `headMeta()` / `headLink()`. **Never** build a TigerSEO head abstraction, and never make a theme
   depend on this module to render a title tag. Uninstall TigerSEO → the head still renders. That's
   the test (§1).
2. **Never write a file into the docroot.** `robots.txt` and `sitemap.xml` are **routes**. A physical
   file in `public/` is silently served by Apache before PHP ever runs (`.htaccess` real-file-first),
   so a static file doesn't just violate the module rule — it *breaks the feature invisibly*. Caches
   go in `var/`. (§5)
3. **Never touch `.htaccess` or any web-server config.** RankMath does. We don't, ever, for any
   reason. That's a platform-wide rule, not an SEO one.
4. **Never learn a content type.** No `if ($type === 'product')`, no table of known post types. The
   module that owns a content type contributes its own schema through the registry. If you're editing
   TigerSEO to support someone else's content, the design has failed (§4).
5. **No phone-home, no account, no upsell, no pro tier.** It's free and BSD. We hold this line for
   themes already; SEO doesn't get an exemption because the category's incumbents all do it.

## Storage: `page.meta.seo`, and nowhere else

- **Page SEO lives in `page.meta.seo`** — one shape, for CMS pages and blog articles alike. Not a
  `seo` table: it's 1:1 with the row, already loaded, versioned free by `page_version`, org-cascaded
  free (§3).
- **Settings live in `config`** — the live-override tier, per-org, no deploy. **Never** an options
  blob, never a settings table ([[config-discipline]]).
- **The `option` table is not for this.** That store is lazy per-user/entity state (a dashboard
  layout, a dismissed nag). SEO meta is eager and intrinsic to the row.
- **Unify, don't tolerate.** The CMS writes `meta.description` and the blog writes
  `meta.seo.description` today. Fix it with a **migration**, not a reader that accepts both — a
  tolerant reader is how a codebase keeps two shapes forever.

## Render data before you add fields

The first phase is not features. Tiger already asks authors for a meta description, a canonical URL,
and an OG image — **and renders none of them.** Fix that before adding a single new input. Any PR that
adds a field while an existing field still doesn't render is pointed the wrong way.

## Conventions specific to this module

- **Slug is `seo`;** classes are `Seo_*` (PSR-0 underscore — ZF1 mandates it for controllers/module
  classes).
- **Routes are declared overrides** (`Tiger_Routing_Overrides`), not `addRoute`. Verify early that a
  dotted path (`robots.txt`) survives the prefix matcher.
- **Sitemap/robots generation is cached** behind a cheap fingerprint (max `updated_at` + row count,
  per org + locale), in `var/`. **No cron** — cPanel has none. Rebuild on miss, self-heal.
- **Every emitted tag is escaped.** This module's entire job is putting user-authored strings into
  `<head>`, which is an XSS surface with a bow on it. `head_html` is trusted-by-policy (admin-only,
  like `phtml` pages); *everything else* is escaped, always, no exceptions for "it's just a title."
- **Admin screens** follow ADMIN.md exactly; settings register into the shared Settings tree.
- **All strings are `seo.*` translate keys.** Including admin labels and guidance copy.
- **Length guidance is pixel-width, not character count** — a character count is a lie that ships in
  every competitor.

## Anti-patterns (don't)

- Don't build a head abstraction; use `Zend_View_Helper_Head*`.
- Don't make a theme or core depend on TigerSEO for basic head rendering.
- Don't write `robots.txt`, `sitemap.xml`, or anything else into the docroot.
- Don't edit `.htaccess` or any web-server config.
- Don't add a `seo` table for page metadata, or a route-keyed one for module URLs.
- Don't put settings in a new table or a serialized blob — `config` rows.
- Don't hardcode knowledge of another module's content type.
- Don't emit an unescaped user string into `<head>` (except the declared `head_html` hatch).
- Don't rely on cron.
- Don't add a field while an existing collected field still isn't rendered.
- Don't build the SEO score in v1 — and when you do, don't let it block publishing (§8).
