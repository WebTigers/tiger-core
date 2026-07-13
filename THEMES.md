# Tiger — Installable Themes & the CMS

How a **theme** is packaged, sold in the marketplace, installed through the Module Manager, and
how its material meets the CMS **without** the WordPress trap where switching a theme breaks every
page. Read this before building the theme-provenance columns, the block registry, or a marketplace
theme. For the platform *why* read [ARCHITECTURE.md](ARCHITECTURE.md) (esp. §9 theming, §5 config
cascade, §6 modules); for the CMS surface read [FEATURES.md](FEATURES.md); for the `/api` contract
read [WEBSERVICES.md](WEBSERVICES.md).

> **Status: design-of-record (proposed, not built).** This records the decisions and their
> rationale so we don't relitigate them or drift when the code lands. Where it says "a theme
> does X," that's the target behavior. Current reality: Tiger has **theme-as-a-path** + skins
> (built) and a CMS `page` store with a GrapesJS builder (built). **Prototyped** (on the Porto POC —
> a licensed vendor theme, off-repo): **theme-as-a-module** (a `theme-<name>` module resolves as the
> active theme), **file-based theme pages** (`content/` + the ThemeContent plugin cascade, §8a), and
> **file-based builder components** (`components/` + `Tiger_Theme`, §8a). Still roadmap: the semantic
> block contract (§3), provenance columns (§4), and marketplace import.

---

## 0. The one principle everything follows

**Presentation is a *theme* (files, resolved by path). Content is *CMS data* (rows, portable).
The seam between them is a contract of semantic blocks.**

This is not a new philosophy — it's ARCHITECTURE §9 (*"Core emits data + semantic default views;
a theme decides the whole rendering approach. Theme is just a path."*) pushed down into page
content. Hold this line and a theme switch becomes a **config flip**, not a migration.

The WordPress/Divi failure is letting a *page* store **theme-specific markup** — a soup of theme
classes and page-builder shortcodes. Switch the theme and the markup still points at the old one,
so the page breaks. Tiger's answer: a page stores **intent** (which semantic blocks, with what
content); the *theme* supplies **appearance** (how each block renders). Same content, new
renderers, re-styled — not broken.

If you're ever unsure where something belongs, ask the ARCHITECTURE §0 question: *"Who owns this,
and what happens on uninstall / theme-switch?"* Presentation disappears with its theme; content
never does.

---

## 1. Three tiers of theme material

Everything a theme ships falls into exactly one tier, and each is stored and namespaced
differently. This table is the whole model; the rest of the doc is detail.

| Tier | What | Lives as | In the CMS DB? | Namespaced by | Survives theme switch? |
|---|---|---|---|---|---|
| **1 — Presentation** | layouts, skins/CSS, fonts, images, **block renderers** | **files** in the theme module dir, path-resolved at render | **no** | filesystem path + prefixed registry key | replaced by the new theme's files |
| **2 — Block contract** | semantic block *types* the builder composes | a **registry** (code/config) + per-theme renderer files | the page stores a **portable block tree**, not theme HTML | block-type key (`hero`, `aurora:split-hero`) | re-rendered by the new theme; unknown types fall back to a snapshot |
| **3 — Starter content** | demo pages / menus / media | **copied** into normal app-owned CMS rows, on explicit import | **yes** (as a fork) | `source` / `source_key` columns + owner-prefixed keys | yes — it's the user's copy now |

The load-bearing consequence: **a theme never *owns* a live CMS content row.** It owns files
(Tier 1), a contract (Tier 2), and a one-time *seed* (Tier 3). Nothing the user made vanishes when
they switch or uninstall.

---

## 2. Tier 1 — Presentation (files, never rows)

Layouts, view scripts, skins, fonts, images, and **block renderers** live in the theme module's
directory and are resolved live by **theme-as-a-path** (ARCHITECTURE §9a) — never imported into
the CMS. This is why *"does the CMS import the theme's pieces?"* is **no** for presentation: the
CMS renders *through* the active theme's files, so a switch is a config change with no data to move.

- **Namespacing needs no DB.** Two installed themes are two module directories
  (`modules/theme-aurora/`, `modules/theme-lumen/`); only the *active* one is woven into the view
  path + asset base (the existing asset symlink on activate). Any block types a theme *adds* carry
  a prefixed registry key (`aurora:split-hero`), the same owner-prefix convention as i18n keys
  (`<module>.*`) and ACL resources.
- **Skins are unchanged** — a skin is still the CSS-variable overlay *within* a theme
  (ARCHITECTURE §9). A marketplace theme may ship several skins; the theme is the heavy axis, the
  skin the light per-tenant one. Nothing here changes the theme/skin split.
- **Uninstall is clean** because it's just files — remove the module, the presentation is gone.

---

## 3. Tier 2 — The block contract (the portability seam)

This is the crux and the escape from lock-in. It reuses three things Tiger already has: the
**shortcode registry**, the **GrapesJS component pattern** (live canvas preview, semantic export),
and the **view-path cascade** (theme → core-default fallback). A "block" unifies all three.

### 3a. What a block is

A **block** is three parts:

1. **A registry entry** — `key`, a **prop schema** (declared slots: `hero{heading, sub, image,
   buttons[]}`), a builder category + icon. Registered like a shortcode / the way
   `registerBootstrapBlocks` seeds the palette today.
2. **A builder component** — the drag-drop UI, trait editors, and a **live canvas preview**
   (renders the real thing while editing).
3. **A renderer** — resolved *per active theme*: `themes/<theme>/blocks/hero.phtml`, with a
   fallback to the core default `blocks/hero.phtml` through the **existing view-path cascade**
   (theme wins, else core). Presentation lives here (Tier 1); the block is the contract.

The proof-of-concept already ships: the CMS **Menu** builder component renders a live menu in the
canvas yet **exports the `[menu name="X"]` shortcode**, staying dynamic + auth-filtered at view
time. A contract block is the same move generalized — it edits richly, previews live, but
**persists a semantic invocation** (props), and the *theme* renders it.

### 3b. What the page stores

The page's `meta.builder` (GrapesJS project) stores a **tree of block types + content props** —
portable, theme-agnostic. The rendered HTML in `page.body` is a **snapshot** of the active theme's
output (Tiger already stores both). So every page carries *both* its portable source **and** a
safety-net snapshot — the platform is already positioned for this.

On render, `Tiger_Cms_Renderer` resolves each block's renderer through the active theme. Switch
theme → same tree → the new theme's renderers → the page re-flows in the new look.

### 3c. Portability is a spectrum, stated honestly

Your instinct is right: *posts are easy, designed pages are not.* So the model grades content by
how portable it actually is, and shows the author which grade a page is in:

| Grade | Content shape | On theme switch |
|---|---|---|
| **Portable** | posts, markdown, HTML prose | re-styled cleanly (theme is pure CSS over semantic markup) |
| **Contract** | a tree of **shared** block types (`hero`, `cta`, `pricing`) | re-rendered by the new theme's renderers — clean |
| **Theme-bound** | a **theme-specific** block (`aurora:split-hero`) or a raw-HTML block using theme classes | renders the **stored snapshot**, flagged *"designed for Aurora — re-open in the builder to re-flow"* |

The theme-bound grade is where WP simply breaks; Tiger instead renders the frozen snapshot and
tells the author, so nothing 500s and nothing is silently lost. That's strictly better than "switch
theme, pray."

### 3d. Extension + graceful degradation

A theme may **extend** the contract with its own namespaced block types (`aurora:split-hero`,
renderer at `themes/aurora/blocks/split-hero.phtml`). If the active theme lacks a renderer for a
block a page uses, resolution is: **active theme → core default → per-block snapshot HTML** (with
the author flag from 3c). Whole-page `body` snapshot is the v1 safety net; per-block snapshot is a
refinement.

---

## 4. Tier 3 — Starter content & DB namespacing

This is the **only** tier that becomes CMS rows, so DB namespacing is small and clean.

### 4a. Import is explicit, a copy, and app-owned thereafter

The theme manifest lists starter pages / menus / media (Tier 3, §9). **Install never seeds the
CMS.** A separate, explicit **"Import starter content"** action copies them into ordinary `page`
rows the user owns from then on — editable, and **not removed on theme switch or uninstall.** Import
is idempotent (skip/rename on key collision). This is the WP "import demo content" step, done as a
one-way copy instead of a live dependency.

### 4b. Provenance columns (not a parallel table)

Add provenance to the content tables (`page`, `menu`, media) — **never** a per-theme side table
(that would overload the schema the way a `wp_options` grab-bag does; see the config-discipline
rule). Three columns:

| Column | Meaning |
|---|---|
| `source` | `user` \| `theme` \| `module` — who created the row |
| `source_key` | the provider key, e.g. `aurora` (null for user-authored) |
| `forked` | `1` once the user edits a theme-seeded row → a theme **update won't clobber it** |

- **Keys** collide-proof by **owner-prefix on seed**: `page_key = "aurora:home"`, same convention
  as i18n and ACL. Row uniqueness stays `(org_id, page_key)` — no schema gymnastics.
- **Queries fall out for free:** "all Aurora pages" (`source_key='aurora'`), "pages orphaned by an
  uninstalled theme," "pages that diverge from their theme's shipped version" (`forked=1`). This is
  exactly why structured columns beat a namespaced string blob.

### 4c. Fork-on-edit = non-destructive updates

The answer to *"switching/updating means you like doing updates"*: when a user edits a
`source='theme'` row, set `forked=1`. A theme **update** refreshes only the untouched copies —
`WHERE source='theme' AND source_key=? AND forked=0` — and leaves the user's edited pages alone.
This is the config **live-override** pattern (the user tier always wins) applied to content. WP
can't do this for theme-shipped files; Tiger can, because the seed is a row, not a file.

---

## 5. A theme *is* a module — managed in the ONE Module Manager

A theme ships as a **module** (ARCHITECTURE §9a), flagged `type = "theme"` in its manifest
(`theme.json` / `module.ini`) so the Module Manager lists it under a **Themes** filter.

**The design principle (Tiger's edge over WP):** *everything installable is managed in one place.*
WordPress scatters management across separate screens — Plugins here, Themes there, and no story at
all for "an app." Tiger has **one Module Manager** for every installable — **code modules, themes,
and (later) whole apps** — same registry, same Install/Activate/Deactivate lifecycle, same
marketplace, just a `type` filter. A theme is not a special citizen with its own admin section; it's
a module whose `type` happens to be `theme`. One surface, one mental model.

### 5a. The lifecycle verbs (all Module-Manager buttons — the user never edits config)

Each is a button that runs a service; the user touches no config table, no filesystem, no SQL.

1. **Install** — the MM downloads the theme's **release zip** (a *complete* artifact: code **+**
   assets, published to the vendor's GH release by a build that runs `fetch-assets` — the same
   vendored-release-zip model as tiger-core) and extracts it into the **app** modules dir
   (`application/modules/theme-<name>/`). *Nothing enters the CMS.* Fully reversible (uninstall = delete).
   > Why the release zip, not a git clone: a theme's repo gitignores its licensed/heavy `assets/`;
   > the release artifact bundles them, so install is one download with nothing left to fetch.
2. **Preview** — see it working *before* committing. The MM sets an **admin-only `tiger_theme`
   cookie**; `_initTheme` honors that cookie over the config (exactly like the skin switcher's
   `tiger_skin` cookie), so the admin browses the **live** site rendered in the theme while everyone
   else sees the current one. Nothing global changes.
3. **Activate** — make it live. The service does exactly two writes, both in code:
   - **config**: `tiger.theme = <key>` via `Tiger_Model_Config` (global scope, or the current
     **org** for multi-tenant) — the live-override tier, effective next request, no deploy.
   - **asset symlink**: reads `assetBase` from the manifest (e.g. `/_porto`) and ensures
     `public/<assetBase> → application/modules/theme-<name>/assets` (the MM already does symlink-on-
     activate for module assets — themes reuse it), then clears the theme-path + docs caches.

   That's the whole payoff of *theme-as-a-path*: **activation is a config row + a symlink — no build,
   no deploy, instant.** Activating B **deactivates** A for that scope (see §5b).
4. **Deactivate** — remove the scope's `tiger.theme` row → the base theme (puma) resolves again. The
   installed files + symlink stay (inert), so re-activating is instant.
5. **Import starter content** — optional, explicit, idempotent; the only step that writes CMS rows
   (§4). Never happens on install.

Keeping these distinct is what makes it safe: install ten themes to browse, **preview** any of them
just for yourself, activate one, and never import a page you didn't ask for.

### 5b. One active theme per scope — but per-org across tenants

"Active" has three axes; only one of them is single-valued:

| Axis | Multiple active? |
|---|---|
| **Per scope** (one site, or one org) | **No — one at a time**, like WP. Activating B deactivates A. |
| **Across tenants** (multi-tenant install) | **Yes** — the active theme is a per-**org** config row, so org A runs Porto while org B runs another theme. Tiger's tenant-branding axis, for free. |
| **Admin vs public** | The **back office is always the platform base theme**; the active theme is the **public-facing** one (§5c). |

### 5c. Context-aware resolution — a public theme never touches the admin

The rule that makes activation *safe*: **`_initTheme` resolves the active theme for PUBLIC requests,
and the base theme (puma) for `/admin` + `/auth`.** Like WordPress — `wp-admin` is always WordPress
chrome; the theme is your front-end. Consequences:

- A public theme (Porto) **never has to reimplement the admin/auth layer**, and **activating it can
  never break the back office** — the one real risk in the whole model.
- Each theme keeps its **own** `assetBase` symlink (`/_porto`, `/_theme`, …), so multiple themes'
  assets coexist and admin (on `/_theme` → base) is untouched while public renders on the active
  theme's base. No repointing of a shared symlink, no collision.

Implementation: branch the theme lookup on the request area (the dispatcher already knows the
module/controller — `admin`/`auth` → base; else → the active-theme config). Small change, and it
retires the POC workaround (a public theme borrowing puma's admin layouts).

---

## 6. Multi-tenant: the payoff WP can't match

Because the active theme is a **per-org config row** and starter content is namespaced by
`(org_id, source_key)`, two tenants on one install can run **different themes with their own
content**, side by side. Theme-switching, provenance, and fork-on-edit are all already
tenant-scoped, so the multi-tenant story needs no extra design — it's the same columns doing
double duty. A single-tenant CMS (WordPress) structurally cannot offer this.

---

## 7. Layouts: files for chrome, an *optional* seeded row for templates

Two "layout" concepts already coexist: **theme layout files** (presentation chrome — header /
footer / nav, `themes/<theme>/layouts/`) and **CMS `layout` rows** (author-made page templates,
`type=layout`). Keep the split:

- **Chrome is a file** (Tier 1) — path-resolved, swapped on theme change.
- A theme **may** seed an **editable** CMS `layout` row as Tier-3 starter content *when it wants
  the author to be able to tweak a template* — but its core chrome is never a row. Default to files;
  reach for a seeded layout row only for genuinely author-editable templates.

This avoids the "which layout wins?" ambiguity: structure is a file, author templates are rows.

---

## 8. What a theme module ships

```
modules/theme-aurora/           (a `theme-<name>` module; resolved purely by path — no Bootstrap)
  theme.json              ; the MANIFEST — key, assetBase, skins, canvasCss, pages, components
  layouts/scripts/        ; Tier 1 — chrome (public layout + per-page slots: title/skin/head/scripts)
  assets/                 ; Tier 1 — fonts, images, js, vendor (served via a public/_<x> symlink)
    skins/                ; Tier 1 — CSS skins (default, aurora-dark, …)
  blocks/                 ; Tier 2 — renderers for the semantic block contract (roadmap)
  components/             ; Tier 2 — GrapesJS block partials (+ tiger:block hint)     [prototyped §8a]
  content/                ; theme-shipped PAGES as body partials (+ tiger:page hint)  [prototyped §8a]
  source/                 ; pristine vendor .html — extraction INPUT, need not ship   [prototyped §8a]
  configs/                ; acl.ini / routes.ini as any module
```

The **manifest** (`theme.json`) declares: the theme `key`; the `assetBase` (its `public/_<x>`
symlink); the `skins` it provides; the `canvasCss` to load into the GrapesJS canvas so its
components preview in-style; the contract blocks it overrides/adds; and a starter-content list. The
manifest is what the Module Manager reads to show the marketplace card, the "extends N blocks" note,
and the import checklist.

---

## 8a. Theme-shipped pages & components — file-based (PROTOTYPED)

A separate, cheaper track from Tier-3 DB starter content, built for the reality that a vendor theme
(e.g. Porto) ships **hundreds of pages**. Putting those in the DB is the wrong tool — they're
presentation, so they live as **files in the theme** and are served through the *one* layout. Two
kinds, both self-describing via a leading HTML-comment hint (the same shape as TigerDocs' `tiger:doc`):

**Pages — `content/<slug>.phtml`.** The page *body* only (not the chrome), led by:
```html
<!-- tiger:page title="Contact Us" skin="default" view="view.contact" css="demos/x.css" -->
```
`Tiger_Controller_Plugin_ThemeContent` (registered after PageDispatch) is the last hop of the slug
chain (ROUTING.md §5): **real controller → CMS `page` (PageDispatch) → theme `content/` partial →
404**. So a DB page always overrides a same-slug theme page (the live-override tier), and the theme's
stock `.html` links resolve (the suffix is stripped). `PageController::themeContentAction` parses the
hint, wires the per-page **skin / view-JS / extra CSS** into the shared layout's `pageHead`/
`pageScripts` slots (the axes that actually vary across a vendor theme — see the analysis in §8b),
and wraps the body. Net: ~one layout + N tiny body files, no per-page DB rows, no repeated chrome
(~70% smaller per page). `source/` holds the pristine vendor `.html` as the **extraction input** for
generating `content/` partials — it need not ship in the distributed theme.

**Components — `components/<id>.phtml`.** A GrapesJS block, led by:
```html
<!-- tiger:block label="Call to Action" category="Porto" icon="fa-bullhorn" -->
```
`Tiger_Theme::components()` reads them; the CMS `designAction` passes them (+ the manifest's
`canvasCss`) to the builder, which registers each as a draggable block that drops the vendor's own
markup and previews in the theme's CSS. This is the concrete, file-based on-ramp to the Tier-2 block
contract (§3): a theme's palette is just a folder of hinted partials.

Both reads go through **`Tiger_Theme`** (`dir()` / `manifest()` / `assetBase()` / `components()` /
`hint()`), which resolves the active theme dir from the `Tiger_ThemeDir` registry entry set at
bootstrap. **Why files, not the DB:** a vendor (or an AI agent) adds/edits a page or a block by
touching exactly **one self-describing file** — no routing, no schema, no chrome — and converting a
static theme is a mechanical `source/*.html → content/*.phtml + hint` pass. Compartmentalization an
agent can work with.

### 8b. A few named layouts + per-page slots (not one layout, not 835)

Analyzing a real vendor theme (Porto, 835 pages): a **shared core** (~13 CSS + 4 JS) underpins every
page, but pages cluster into a **handful of chrome variants** — and it's the **header/footer** that
differ most (Porto: a dark transparent *landing* header vs a light *inner-page* header + breadcrumb
band vs a *shop* header with a cart), on top of lighter axes (**skin**, optional **demo CSS**,
optional **per-view JS**). One shared layout is therefore *too* few — it forces the landing's header
onto every page. So a theme ships a **few named layouts** (`layouts/scripts/<layout>.phtml`, one per
chrome variant), and each `content/` partial names its layout and fills its slots via the hint:
```html
<!-- tiger:page layout="page" skin="default" view="view.contact" css="demos/x.css" -->
```
`PageController::themeContentAction` sets the Zend layout from `layout` (sanitized to a bare name, so
it can't escape the layout dir; default `layout`) and the head/scripts slots from the rest. The
filename **prefix** (`about-*`, `blog-*`, `shop-*`, `index-*`) is a good *grouping* proxy for which
layout a page needs — but confirm the actual header/footer per group before extracting, since the
prefix isn't a clean 1:1 map (several prefixes share the inner-page chrome).

---

## 9. Rejected alternatives (so we don't relitigate)

| Rejected | Why | Chosen instead |
|---|---|---|
| Theme owns live CMS page rows (WP model) | switch/uninstall breaks or deletes user pages | theme owns files + a contract + a one-time seed; content rows are app-owned |
| Page stores theme-specific builder HTML | not portable — the Divi/Elementor lock-in | page stores a **semantic block tree**; theme supplies renderers |
| A separate `theme_page` / per-theme table | schema sprawl, a `wp_options`-style grab-bag | 3 provenance columns on existing content tables |
| Namespacing via a mangled string key only | not queryable (orphans, diffs, per-theme lists) | structured `source`/`source_key` columns + owner-prefixed keys |
| Install auto-seeds the CMS | surprises the user; couples install to content | install ≠ activate ≠ **explicit** import (three verbs) |
| Silent breakage on a missing block type | white screens on switch | resolve active-theme → core-default → **snapshot** + author flag |
| Theme update overwrites edited pages | destroys the user's work | **fork-on-edit** — updates skip `forked=1` rows |

---

## 10. Build order (phasing)

1. **Provenance columns** — `source` / `source_key` / `forked` on `page` (then `menu`, media) +
   the migration; teach `Tiger_Model_Page` finders about them. *(Cheap, unblocks everything.)*
2. **Block registry + renderer cascade** — generalize the shortcode/menu-component pattern into a
   `Tiger_Cms_Block` registry with a per-theme renderer resolved through the view-path cascade;
   port the current Bootstrap blocks to it as the core-default set.
3. **Builder → semantic export** — the GrapesJS components persist block invocations (props), not
   frozen theme HTML, with the `body` snapshot as the safety net; add the portability-grade flag.
4. **Theme = module (marketplace)** — `module.type=theme`, the Themes filter, install/activate
   wired to the module installer + the `tiger.theme` config tier.
5. **Starter content import** — the manifest reader + the explicit, idempotent, app-owned copy.
6. **Marketplace polish** — screenshots, "extends N blocks," per-block snapshot refinement,
   theme-switch preview.

> The concrete, running to-do of **which components to build** (blocks, forms, media, taxonomy —
> what a theme actually drops on a page) lives in [THEME-COMPONENTS.md](THEME-COMPONENTS.md), a
> living checklist keyed to these phases. Group 0 there = the plumbing in #1–#3 above.

---

## 11. Open questions (decide before Phase 2/3)

- **How strict is the contract?** Contract-first with a raw-HTML escape hatch (recommended:
  max portability, honest snapshot for the escape hatch) vs. anything-goes HTML blocks (easier,
  less portable).
- **Import granularity** — à-la-carte pattern insert vs. a whole "install the demo site" button
  (recommended: both — à-la-carte is the primitive, "install demo" is a batch of it).
- **Do we ever let a tenant author its own theme?** Skins already, safely (inert CSS). A full
  theme is code — likely a marketplace/publisher concern, not an in-app tenant one. Deferred.

---

*This document records decisions and their rationale. If you change a decision, update the
relevant section here in the same change — the "why" is the most valuable and most perishable part
of the codebase.*
