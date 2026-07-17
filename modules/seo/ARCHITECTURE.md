# TigerSEO — Architecture & Rationale

This document explains **why TigerSEO is built the way it is**. It favors *rationale* over reference.
Read [§10 Rejected alternatives](#10-rejected-alternatives-so-we-dont-relitigate-them) before
"improving" anything here.

> **Status: design-of-record (proposed, not built).** Where this says "TigerSEO does X," that's the
> target behavior. §0 is the audit of what exists today and is the *reason* for most of what follows.

Reference model: **RankMath** — the feature set is right, the architecture is the cautionary tale.
Where this doc says "RankMath does X," that's a thing we're deliberately not doing.

---

## 0. The audit: what Tiger has today (2026-07-17)

Verified by grep, not memory. This is the starting line:

| Thing | State |
|---|---|
| `<meta name="description">` | **never emitted** — the public layout is `charset`, `viewport`, `<title>`, full stop |
| `robots.txt`, `sitemap.xml`, `og:*`, `twitter:card`, JSON-LD, `rel=canonical`, `noindex` | **zero occurrences** anywhere in the codebase |
| `Zend_View_Helper_HeadMeta` / `HeadTitle` / `HeadLink` / `Placeholder` | **ship in TigerZF; used by nothing** |
| CMS page SEO fields | `page.meta.description`, `.head_html`, `.body_scripts` — collected, **description never rendered** |
| Blog article SEO fields | `page.meta.seo.{seo_title, seo_description, og_image_id, canonical}` — collected, **never rendered** |
| `page_redirect` | **built** — `from_slug`/`to_slug`/`locale`/`code` (301\|302), org-scoped, auto-written on slug change, dispatched by a plugin. No UI. |
| `media.alt_text` | **built** |
| Locale-prefix routes (`/es/…`) + language-only locales | **built** |

Two findings drive this whole document.

**Finding 1 — the data is captured and thrown away.** An author can fill in a canonical URL and an OG
image on an article, save it, and nothing renders. That's not a missing feature; it's a broken
promise already shipped. And the two collectors disagree: the CMS writes `meta.description`, the blog
writes `meta.seo.description`. **Same column, two shapes, neither rendered.** Unify before extending.

**Finding 2 — the head abstraction already exists and Tiger ignored it.** ZF1's placeholder helpers
have been in the engine the whole time. Tiger's layouts hardcode `<title>` and paste a raw
`head_html` blob instead. So the foundational task isn't "build a head registry" — it's **adopt the
one in the box**, which is platform hygiene that TigerSEO merely benefits from (§1).

---

## 1. The `<head>` is a registry — and it's core's, not TigerSEO's

**The single most important structural decision: the head registry belongs to tiger-core, not to this
module.** A theme needs `<title>` and `<meta>` whether or not TigerSEO is installed. If TigerSEO owned
the head, every theme would depend on an SEO module to render a title tag — absurd, and a coupling
that would never come out again.

So the work splits cleanly:

| Where | What | Depends on |
|---|---|---|
| **tiger-core** (PUMA layouts) | call `$this->headTitle()` / `$this->headMeta()` / `$this->headLink()` instead of hardcoding `<title>` and echoing `pageHead` | nothing — it's using TigerZF's own helpers |
| **TigerSEO** | *append to those containers* — description, OG, Twitter, canonical, hreflang, robots | tiger-core only |

**Neither side learns about the other.** Core doesn't know SEO exists; TigerSEO registers no custom
registry. Uninstall TigerSEO and the head still renders — just with less in it. That's the test a
correct design passes.

**Why a registry at all**, rather than the theme rendering whatever the page hands it: because more
than one party has a legitimate claim on the `<head>` — the theme (viewport, fonts, preloads), a
module (TigerDocs' search, a widget's CSS), the page author (`head_html`), and TigerSEO. A string
concat has no conflict resolution and no ordering; a container has both, and ZF1's already implements
`set` / `append` / `prepend` semantics. **Last authority wins, deterministically, and you can ask what
it decided** — the same explainability instinct as the ACL simulator.

**`head_html` is not removed.** It stays as the escape hatch for the genuinely weird (a verification
tag, a one-off vendor snippet), because there is always one. It just stops being the *only* road. Per
the house preference: add the new alongside the old, don't bury what works.

---

## 2. What "more cleanly than RankMath" actually means

RankMath's feature list is largely correct — it won by shipping what Yoast gated. Its *architecture*
is the thing to avoid, and naming the specific sins keeps us honest:

| RankMath | Why it's like that | TigerSEO |
|---|---|---|
| Enormous serialized blobs in `wp_options`, autoloaded every request | WP has no config tier | `config` rows — the live-override tier, per-org, lean ([[config-discipline]]) |
| ~2,000 stringly-typed hooks | WP is procedural; hooks are its only seam | typed registries + module-contributed schema (§4) |
| Knows every content type itself (WooCommerce, EDD, …) | no way for a plugin to describe itself | **content types describe themselves** — blog owns `Article` (§4) |
| Edits `.htaccess` and writes `robots.txt` into the docroot | WP's install model permits it | **never** — a module touches no web-server config and writes no docroot file (§5) |
| Phones home; account required for some features | growth loop | never |
| In-admin upsell on every screen | freemium | it's free, BSD, done |
| Single-site | WP is single-tenant | per-org, because `config` already is (§7) |
| SEO score trains you to write for a checklist | it demos well | deferred, and scoped honestly (§8) |

**The thesis in one line: SEO output should be a *rendering* concern of the platform, not a plugin
stapled to the side of it.** Everything above follows from taking that literally.

---

## 3. Storage: `page.meta.seo` — no new table

SEO metadata for a page lives in the **`page.meta` JSON** the page already carries, under one `seo`
key. No `seo` table, no join.

The reasoning is [[config-discipline]] applied to content — **split by access pattern**:

- SEO meta is read on **every public render**, and always for **exactly the row being rendered**. It's
  1:1 with the page and already in memory the moment the page loads. A `seo` table would add a join to
  every page view to fetch data the page row could have carried for free.
- It's **versioned for free** by `page_version` — restore a page, restore its SEO with it. A side
  table would silently not do that, and nobody would notice until they restored a version and lost
  their canonical.
- It's **org-cascaded for free** — the page store already resolves tenant-over-global.
- It is **not** `option`-table material: that store is for lazy, on-demand, per-user/entity state
  (a dashboard layout, a dismissed nag). This is eager and intrinsic to the row.

**The unification (do this first, it's the whole Finding-1 fix):** one shape, `page.meta.seo`, used by
CMS pages and blog articles alike:

```
page.meta.seo = { title, description, canonical, robots: {index, follow}, og: {…}, twitter: {…} }
```

The CMS's existing `meta.description` migrates into `meta.seo.description`; the blog's
`meta.seo.seo_title` / `seo_description` lose their stutter. **Write a migration for the existing
rows** — a reader that tolerates both shapes forever is how you end up with both shapes forever.

### 3a. What about pages that aren't `page` rows?

Module routes (`/docs`, a blog archive, a module's own screen) have no `page` row, so they have no
`page.meta`. **They don't get one.** A module contributing its own head entries is the *normal* case,
not a special case — it knows its title and description better than a database row would, and it says
so through the same registry as everyone else (§1). Resisting a route-keyed `seo` table here is what
keeps this module small.

---

## 4. Structured data: a typed registry, not a God plugin

RankMath ships a schema generator that knows about WooCommerce products, EDD downloads, recipes, and
so on — because a WP plugin has no way to *ask* another plugin what its content is.

Tiger inverts it: **the module that owns a content type owns its schema.**

- TigerSEO provides the registry, the JSON-LD serializer, `@graph` assembly, escaping, and the
  `WebSite` / `Organization` / `BreadcrumbList` basics that come from platform data it already has
  (site name from `config`, the org, the menu tree).
- **`blog` contributes `Article`/`BlogPosting`** from its own fields. `media` contributes
  `ImageObject`. A future commerce module contributes `Product`. TigerSEO never learns their shapes.
- The registry is the **shortcode-registry pattern** generalized — the same move THEMES.md §3 makes
  for blocks. Consistency with an existing seam beats a novel one.

If TigerSEO is ever tempted to `if ($type === 'product')`, the design has failed.

---

## 5. `robots.txt` and `sitemap.xml` are **routes**, never files

Both are served by controllers. **TigerSEO must never write a file into the docroot.**

Two independent reasons, and either alone is sufficient:

1. **The module rule** (ARCHITECTURE §6, core): a module never touches infrastructure — no web-server
   config, no filesystem outside its own dir. Writing to the docroot breaks 1-click install and the
   ownership boundary. RankMath edits `.htaccess`; we don't get to.
2. **The cPanel landmine** ([[cpanel-hosting-constraint]]): `public/.htaccess` serves real files first
   (`RewriteCond %{REQUEST_FILENAME} -s [OR] -l [OR] -d` → `[L]`). **A physical `robots.txt` in the
   docroot silently shadows the route** and no amount of PHP will run. Neither file exists today, so
   the routes are free — and this is exactly the bug that costs a day when someone "helpfully" drops a
   static one in.

Both are **declared route overrides** (`Tiger_Routing_Overrides`), not `addRoute` calls (ROUTING.md
§2). Verify early that a dotted path (`robots.txt`) survives the prefix matcher — the plugin bails
when a real controller claims the URL, and there's no controller named `robots.txt`, so it should fall
through cleanly. *(The `.htaccess` dotfile block is leading-dot only, so it doesn't interfere.)*

### 5a. Sitemaps without cron

cPanel can't be assumed to have cron ([[cpanel-hosting-constraint]]), so a nightly rebuild is out.
**Generate on demand behind a fingerprint-invalidated build cache** — the TigerDocs pattern: cheap
fingerprint (max `updated_at` + row count, per org + locale) → miss → rebuild → cache inside the app
root (`var/`, never the docroot, per §5). Self-healing, no DB table, fleet-safe, no cron.

At size: a **sitemap index** plus paginated children (50k URL / 50MB caps are the spec). Don't design
for a million URLs on day one, but don't design a shape that can't get there — the index is cheap now
and a rewrite later.

---

## 6. hreflang is nearly free — take the win

Tiger already resolves `/es/anything` on every route, persists the choice, and stores one `page` row
per language (FEATURES: semantic locale URLs, language-only locales). So the sibling set for a URL is
**already a query Tiger can answer**, which means `hreflang` + `x-default` is mostly serialization.

This is worth calling out because it's a genuine platform dividend: in WordPress, multilingual SEO
means Polylang/WPML plus an SEO addon, and it's a perennial mess. Tiger gets it because locales were
in the routing model from the start. **Ship it in the first phase**, not as an advanced feature — it's
one of the few places the architecture visibly pays the user back.

---

## 7. Multi-tenant, because `config` already is

SEO settings are `config` rows, so per-org SEO falls out with no extra design — org A and org B on one
install get their own titles, robots policy, and verification tags. Same mechanism as per-org theming.
Sitemaps and robots are already org-scoped because the `page` store is.

Nothing to build here. It's a consequence, and it's the kind of thing a single-tenant CMS can't
retrofit.

---

## 8. Content analysis (the green-light score) — deferred, and honestly

**Out of v1. Scoped, not built.**

It's RankMath's headline and the reason people pick it over Yoast — and it's the biggest lift here
(keyword analysis, readability, a pile of editor JS) while being the *least* architectural: it bolts
onto the editor and changes nothing underneath. So it's the correct thing to defer: the foundation
doesn't move to accommodate it later.

When it's built, build it with a spine: the score is **advisory**, it never blocks publishing, and it
never claims to know what Google thinks. A checklist that turns writers into keyword-stuffers is worse
than no checklist. If we can't ship one that's honest about being a heuristic, not shipping is a
defensible product stance.

---

## 9. Phasing

1. **Head adoption + unification (the gate).** Core's layouts move to `headTitle`/`headMeta`/
   `headLink`. Unify to `page.meta.seo` + migrate existing rows. Render **description, canonical,
   robots** — i.e. make the data Tiger already collects actually work. *(Everything else depends on
   this; nothing else is worth doing before it.)*
2. **Social.** OG + Twitter cards, `og_image_id` → the `media` row (URL + real dimensions). Finally
   renders a field the blog editor has been collecting all along.
3. **hreflang** (§6) — cheap, high-signal, mostly free.
4. **robots.txt + sitemap.xml** as routes, with the build cache (§5).
5. **Structured data** — the registry + `WebSite`/`Organization`/`BreadcrumbList`; `blog` contributes
   `Article` (§4).
6. **Redirects UI + 404 monitor** — a UI over the **existing** `page_redirect`, plus a `seo_404` table
   (the module's only new table) and "promote a 404 to a redirect."
7. **Later:** content analysis (§8), IndexNow, Search Console, image SEO automation, news/video
   sitemaps.

---

## 10. Rejected alternatives (so we don't relitigate them)

| Rejected | Why | Chosen instead |
|---|---|---|
| TigerSEO owns the head registry | every theme would depend on an SEO module to render `<title>`; a coupling that never comes back out | **core** adopts TigerZF's `HeadMeta`/`HeadTitle`/`HeadLink`; TigerSEO just appends (§1) |
| Build a bespoke head/meta abstraction | ZF1 already ships placeholder containers with `set`/`append`/`prepend` and ordering — we'd be reinventing what's in the box, badly | adopt `Zend_View_Helper_Head*` (§0 Finding 2) |
| A dedicated `seo` table | a join on every page render for data that's 1:1 with the row, loses free versioning via `page_version` and the org cascade | `page.meta.seo` (§3) |
| SEO settings in a new table / an options blob | the `wp_options` mistake RankMath is the poster child for | `config` rows — live-override, per-org (§2, §7) |
| Keep both `meta.description` and `meta.seo.description` and read either | a tolerant reader is how you keep two shapes forever | **unify + migrate the rows** (§3) |
| A route-keyed `seo` table for non-page URLs | a table to hold what a module already knows about itself | modules contribute their own head entries (§3a) |
| TigerSEO knows each content type's schema | RankMath's God-plugin shape; can't extend without editing us | **typed registry** — content types describe themselves (§4) |
| Writing `robots.txt` / `sitemap.xml` into the docroot | modules never touch infra; **and** cPanel's real-file-first rule makes a physical file silently shadow the route | **routes** + a `var/` build cache (§5) |
| Editing `.htaccess` (RankMath does) | never, for any module, for any reason | PHP-layer route overrides (ROUTING.md) |
| Cron-generated sitemaps | cPanel has no guaranteed cron | on-demand + fingerprint-invalidated cache (§5a) |
| Content analysis in v1 | biggest lift, least architectural, bolts on cleanly later | deferred and scoped (§8) |
| Phone-home / account-gated features / in-admin upsell | it's free and BSD; and we hold that line for themes already | none of it, ever |

---

*This document records decisions and their rationale. If you change a decision, update the relevant
section here in the same change — the "why" is the most valuable and most perishable part.*
