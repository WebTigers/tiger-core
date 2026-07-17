# TigerSEO — Features

A factual inventory of what TigerSEO will do. For the *why* see [ARCHITECTURE.md](ARCHITECTURE.md);
for the conventions to build it see [AGENTS.md](AGENTS.md).

> **Status: design-of-record (proposed, not built).** Nothing below ships yet — every item is a
> target, not a claim. As things land, move them into plain present tense and keep this file an
> inventory rather than marketing. The **Deliberately absent** section is as load-bearing as the rest.

Free, **BSD-3-Clause**, public. No pro tier, no account, no phone-home, no upsell.

## The `<head>`

- **A real registry.** Title, meta, and link tags are contributed through `Zend_View_Helper_HeadTitle`
  / `HeadMeta` / `HeadLink` — the helpers TigerZF has always shipped and Tiger never used. Themes,
  modules, the page author, and TigerSEO all contribute; last authority wins, deterministically.
  Uninstall TigerSEO and the head still renders, just with less in it.
- **Title templates** with variables (`%title% — %sitename%`), per content type, org-scoped,
  overridable per page.
- **Meta description**, per page — *rendered*, which today it is not.
- **Canonical** — explicit per page, else self-referencing.
- **Meta robots** — `index`/`noindex`, `follow`/`nofollow`, plus `noarchive`/`nosnippet`/`max-*`
  controls, per page and by default per content type.
- **`head_html` still works.** The raw escape hatch stays for verification tags and one-off vendor
  snippets. It stops being the only road; it doesn't stop being a road.

## Social

- **Open Graph** — `og:title`, `og:description`, `og:image`, `og:type`, `og:url`, `og:site_name`,
  `article:published_time`/`modified_time`/`author`.
- **Twitter cards** — `summary` / `summary_large_image`, falling back to OG rather than duplicating it.
- **`og_image_id` finally renders.** The blog editor already collects it; TigerSEO resolves it through
  the `media` row for a real URL and real dimensions (`og:image:width`/`height`), with a per-org
  fallback image.

## International

- **hreflang + `x-default`**, generated from the locale siblings Tiger already stores (one `page` row
  per language) and the locale-prefix routes it already resolves. Ships in an early phase — it's
  mostly serialization, and it's the sort of thing that costs a WordPress site two plugins and an
  argument.

## Structured data (JSON-LD)

- **A typed registry.** TigerSEO supplies the serializer, `@graph` assembly, and the platform-level
  types it can derive from data it already has: `WebSite`, `Organization`, `BreadcrumbList` (from the
  menu tree).
- **Content types describe themselves.** The `blog` module contributes `Article`/`BlogPosting`;
  `media` contributes `ImageObject`; a future commerce module contributes `Product`. TigerSEO never
  learns anyone else's shape and never needs editing to support a new one.

## robots.txt & sitemaps

- **`/robots.txt` is a route**, generated from config + per-org rules, editable in the admin. Never a
  file in the docroot — on cPanel a real file silently shadows the route (ARCHITECTURE §5).
- **`/sitemap.xml` is a route** — a sitemap index plus paginated children, org- and locale-scoped,
  with `lastmod` from the page rows.
- **No cron required.** Generated on demand behind a fingerprint-invalidated build cache in `var/`
  (the TigerDocs pattern) — self-healing, fleet-safe, and it works on a shared host with no shell.

## Redirects & 404s

- **A UI over the redirects Tiger already has.** `page_redirect` is built, org-scoped, locale-aware,
  301/302, and already auto-written when a slug changes. This is a management screen, not an engine —
  list, search, add, edit, soft-delete.
- **404 monitor** — a `seo_404` table (the module's only new table) logging misses with referrer and
  hit count, plus one-click **promote a 404 to a redirect**.

## Multi-tenant

- **Every setting is a `config` row**, so all of it is per-org and live-editable with no deploy —
  titles, robots policy, verification tags, default social image. Sitemaps and robots are org-scoped
  because the `page` store already is. Nothing extra to build; it's a consequence of the platform.

## Admin

- **A per-page SEO panel** in the CMS and blog editors — title, description, canonical, robots,
  social, with a **live Google/social preview** and honest length guidance (pixel-width, not a
  character count that lies).
- **Settings screens** registered into the shared Settings tree, built per ADMIN.md — no bespoke shell.

## Deliberately absent

Their absence is a decision, not an oversight (ARCHITECTURE §10):

- **No `.htaccess` editing.** No module touches web-server config, for any reason.
- **No files written into the docroot.** robots and sitemap are routes.
- **No options blob.** Settings are `config` rows, not a serialized autoloaded lump.
- **No `seo` table for page metadata.** It lives in `page.meta.seo` — versioned and org-cascaded free.
- **No content-type knowledge.** TigerSEO never contains `if ($type === 'product')`.
- **No phone-home, no account, no upsell, no pro tier.**
- **No SEO score in v1** (see below).

## Planned, not committed

- **Content analysis / the green-light score** — deferred out of v1 (ARCHITECTURE §8). Biggest lift,
  least architectural, bolts on later without moving the foundation. When built: advisory only, never
  blocks publishing, never pretends to know what Google thinks.
- **IndexNow / instant indexing**, Search Console integration, image-SEO automation (auto `alt` from
  `media`), news/video sitemaps.

## Blocked on work elsewhere

- **tiger-core** — the PUMA layouts must adopt `headTitle`/`headMeta`/`headLink` instead of hardcoding
  `<title>` and echoing `pageHead`. **This gates everything**, and it's platform hygiene that stands on
  its own merits — it is *not* an SEO feature and shouldn't be argued for as one.
- **The `page.meta.seo` unification + row migration** — touches `modules/cms` and `modules/blog`, both
  first-party, both in tiger-core.
