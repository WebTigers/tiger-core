# TigerSEO

SEO for [Tiger](https://github.com/WebTigers/Tiger) — the `<head>`, structured data, sitemaps,
robots, and redirects — done as platform plumbing rather than as a plugin that pastes strings into
your template.

> **Status: design-of-record (proposed, not built).** No code yet. These four documents are the scope:
> [ARCHITECTURE.md](ARCHITECTURE.md) (the *why* + rejected alternatives), [FEATURES.md](FEATURES.md)
> (what it will do, and what it deliberately won't), [AGENTS.md](AGENTS.md) (conventions for whoever
> builds it — human or AI).

**Free, BSD-3-Clause, public** — like TigerDocs and TigerShield. SEO is table stakes for anything with
a CMS; it isn't a thing you sell back to someone who already installed the platform.

## The pitch

Everything RankMath does that's worth doing, minus the parts that made RankMath what it is: no options
blob, no ~2,000 hooks, no phone-home, no in-admin upsell, no editing your `.htaccess` or writing files
into your docroot.

The short version of the design:

- **The `<head>` is a registry, not a string.** Modules, themes, and TigerSEO all contribute typed
  entries and the last authority wins deterministically — using `Zend_View_Helper_HeadMeta` /
  `HeadTitle` / `HeadLink`, which **already ship in TigerZF and which Tiger has never used** (§1).
- **One SEO shape**, stored in `page.meta.seo`, versioned for free by `page_version`, loaded with the
  page row it belongs to. No new table, no join on every render (§3).
- **Structured data is a typed registry** — the `blog` module says what an Article is; TigerSEO never
  learns about anyone's content type (§4).
- **Consume what Tiger already has:** `page_redirect` (301s, org-scoped, auto-written on slug change)
  *is* a redirections engine; `media.alt_text` is image SEO; locale-prefix routes make **hreflang**
  nearly free; the CMS shortcode registry and TigerDocs' fingerprint-invalidated build cache are the
  models for schema and sitemaps.
- **Multi-tenant from line one** — SEO settings are `config` rows, so they're per-org and live-editable
  with no deploy. A single-tenant CMS structurally cannot do this.

## What exists today (the honest starting point)

Tiger's public `<head>` is `charset`, `viewport`, and `<title>`. That's all of it.

Meanwhile the CMS page editor *collects* a meta description, and the `blog` module *collects*
`seo_title`, `seo_description`, `og_image_id`, and `canonical` — and **none of it is ever rendered.**
An author can set a canonical URL on an article today, save it, and the tag never appears. The only
thing that reaches the `<head>` is `head_html`: a raw textarea, emitted unescaped into the theme's
`pageHead` slot. That's the "paste your meta tags here" hatch RankMath exists to replace.

So TigerSEO's first job is not features. It's rendering data Tiger already asks people to type.

## Layout (planned)

| Path | What |
|---|---|
| `module.json` | manifest — slug `seo`, `type: plugin` |
| `library/` | `Seo_Head`, `Seo_Schema` registry, `Seo_Sitemap`, resolvers |
| `controllers/` | `robots.txt` + `sitemap.xml` routes; the admin screens |
| `services/` | `Seo_Service_Settings`, `Seo_Service_Redirect` — the `/api` surface |
| `views/` | settings, redirects, the per-page SEO panel partial |
| `configs/` | `acl.ini`, `routes.ini`, `dependency.ini` |
| `migrations/` | `seo_404` (the 404 monitor) — the only new table, and only in a later phase |
| `languages/` | `seo.*` keys |

---

Built by WebTigers. Licensed BSD-3-Clause. Tiger™ and WebTigers™ are trademarks of WebTigers.
