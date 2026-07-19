# AGENTS.md — working on the `blog` module

Instructions for an AI assistant (or a new contributor) working on Tiger's **blog** module. For
platform conventions read the root **AGENTS.md** (`read.guide` with no module). This file is the
module-specific layer; match the surrounding style.

> The blog/articles feature: a Medium-style article editor, the public front-end (index / article /
> category + tag archives / RSS feed), and category+tag taxonomy. An article is a `page` row; the
> content engine stays in the platform layer.

## The one thing to know: an article IS a `page` row

`Blog_Model_Post extends Tiger_Model_Page`, scoped to `type='article'`. You inherit versioning, slug
301s, tenancy, i18n, and scheduling **for free** — don't build a posts table. Scalar metadata rides in
`page.meta` JSON, not columns.

## Where things live

- `controllers/IndexController.php` — public front-end (guest): `/blog`, `/blog/<slug>`,
  `/blog/category/<slug>`, `/blog/tag/<slug>`, `/blog/feed` (RSS).
- `controllers/PostController.php` — `/blog/post` (list) + `/blog/post/edit` (admin).
- `services/` — `Post`, `Taxonomy`. `forms/Post.php`.
- `models/` — **`Blog_Model_Post`** (extends `Tiger_Model_Page`) + **`Blog_Model_Taxonomy`** (the
  `taxonomy` table + the `page_taxonomy` join). These ARE owned (the article + taxonomy layer).

## The `/api` surface (admin+, except the guest front-end)

- **`Blog_Service_Post`** — `datatable` (type=article), `save` (rejects reserved slugs; syncs
  comma-typed categories/tags to term ids, creating new terms), `delete`, `restore`.
- **`Blog_Service_Taxonomy`** — `listTerms` (category|tag picker). **Read-only** — terms are minted
  lazily on post save, there's no term-admin write path yet.

## Conventions + gotchas (this module)

- **Scalar metadata lives in `page.meta`** (kicker, subtitle, preamble, excerpt, feature_media_id,
  author_id, reading_time, allow_comments, nested `seo{title,description,og_image_id,canonical}`).
  `packMeta()`/`unpackMeta()` (+ `META_DEFAULTS`) are the only correct read/write path; because it's in
  `meta`, it's **versioned for free**.
- **Only taxonomy is relational.** Categories/tags are `taxonomy` rows joined via `page_taxonomy`.
  `syncPage()` full-deletes+reinserts and runs as a **separate follow-on write after the page save** —
  NOT inside the article's transaction/version snapshot. Terms are minted lazily by `findOrCreate`
  (matched by slug, so "Web Dev" == "web-dev").
- **Reserved slugs** `post|category|tag|feed|index` are rejected in `save` — they'd be shadowed by the
  routes.
- **Routes are imperative `addRoute` in the Bootstrap — a deliberate exception** to the
  `Tiger_Routing_Overrides` convention (it needs `:slug` shapes). **Order is load-bearing:** ZF1's
  rewrite router is LIFO (newest-first), so `blog_admin` (`/blog/post`) is added LAST to shadow
  `blog/:slug`. Touch `_initBlogRoutes()` carefully.
- **The editor is a zero-dep Medium-style contentEditable** (`execCommand` toolbar) with a visual ↔
  HTML-source toggle backed by CodeMirror; `body` is stored as raw HTML. **No WAF base64 shim here** —
  unlike the CMS page editor, the body posts raw (add `*_b64` if article bodies ever trip the WAF).
- **Org/locale scoping mirrors `Tiger_Model_Page`** — tenant term/article overrides the global one;
  queries are language-only (`en`/`es`).
- **SEO/sitemap are guarded optional integrations** — the front-end calls `Seo_Service_Head`/`Schema`
  only `if (class_exists)` (articles render via this controller, not PageDispatch, so no
  `Seo_Plugin_Head`); the sitemap contribution is guarded on `Tiger_Sitemap`. Never hard-depend on
  those modules.

## ACL

guest: `Blog_IndexController` (public). admin: `Blog_PostController` + `Blog_Service_Post` +
`Blog_Service_Taxonomy`.

## Do / Don't

- **Do** read/write article metadata only through `packMeta`/`unpackMeta`.
- **Do** keep taxonomy sync a follow-on write (the join has no versioning).
- **Don't** add a posts table, **don't** let an article claim a reserved slug, **don't** reorder the
  Bootstrap routes without understanding the LIFO shadow.
