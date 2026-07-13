# Tiger — Theme component build-list

The concrete, living checklist of **components a theme drops onto a page** — blocks, forms, media,
taxonomy. It's the companion to the *design-of-record* in [THEMES.md](THEMES.md): §3 defines the
semantic **block contract**, §10 the **phasing**; this file is the running to-do keyed to it. Tick
items off as they land.

**Legend:** `[new]` build from scratch · `[have]` a backend piece already exists — this is a
**wire-up** into the builder, not a ground-up build.

**Sequencing:** do **Group 0 first** — almost everything else is only *portable* once the block
registry + renderer cascade + semantic export exist. Then the cheap **Group D** blocks to prove the
contract end-to-end, then the data-backed **Group A**. The rest is parallelizable.

---

## Group 0 — 🔩 Foundational plumbing (the gate — do first)
- [ ] Block registry + per-theme renderer cascade (`Tiger_Cms_Block`) — THEMES.md §3 / build-order #2
- [ ] Provenance columns (`source` / `source_key` / `forked`) on `page`, then `menu`/media — build-order #1
- [ ] GrapesJS semantic export — persist block **props**, not frozen theme HTML — build-order #3
- [ ] Component discovery (`components/<id>.phtml` + `tiger:block` hint) — *prototyped on Porto*

## Group A — Content types & taxonomy (the dynamic/data tier)
- [ ] Posts with meta `[new]` — blog post type (excerpt, featured image, author, date); reuse the CMS `page` store + existing versioning/scheduling
- [ ] Taxonomy: Categories + Tags `[new]` — shared term model + relationships + archive pages
- [ ] Author / byline + author archive `[new]`
- [ ] Related / Recent / Popular posts `[new]` (widget variants)
- [ ] Comments / discussion `[new]` — moderation, ACL-gated *(may be its own module)*
- [ ] RSS / Atom feed for posts `[new]`

## Group B — Forms
- [ ] Mail Form `[new]` — contact/newsletter block → `Tiger_Form` + `/api` + `Tiger_Mail` (replaces SB Forms; **Grey Mist is the first consumer**)
- [ ] SMTP config admin screen `[have]` — `Tiger_Mail` SMTP transport exists; needs a Settings UI
- [ ] Site search block `[have]` — reuse the TigerDocs ⌘K / DataTables patterns
- [ ] reCAPTCHA in the Mail Form `[have]` — `Tiger_Form_Element_Recaptcha` already exists; just expose it

## Group C — Media & galleries
- [ ] Carousel `[new]` — Bootstrap-native carousel block
- [ ] RevSlider `[new]` — hero slider; replaces Porto's commercial Revolution Slider
- [ ] Lightbox `[have]` — package **TigerLightbox** as a builder component
- [ ] Image gallery / grid (masonry) `[new]`
- [ ] Video embed `[new]` — self-hosted / privacy-first (no third-party phone-home)
- [ ] *Media picker already exists (`tiger.media-picker.js`) — reuse, no build*

## Group D — Marketing / layout blocks (static GrapesJS palette — cheap, high-value; the contract proof)
- [ ] Hero / masthead `[new]`
- [ ] Call-to-action (CTA) `[new]`
- [ ] Feature grid / icon cards `[new]`
- [ ] Pricing table `[new]`
- [ ] Testimonials `[new]`
- [ ] Team / staff `[new]`
- [ ] Stats / counters `[new]`
- [ ] Accordion / FAQ `[new]`
- [ ] Tabs `[new]`
- [ ] Timeline / process steps `[new]`
- [ ] Logo cloud / partners `[new]`

## Group E — Navigation
- [ ] Menu block `[have]` — `Tiger_Menu` (shortcode / helper / `getHTML`); expose as a builder block
- [ ] Mega menu `[have]` — *prototyped on Porto*; generalize
- [ ] Breadcrumbs `[new]` — tie to menu/route
- [ ] Footer builder (columns) `[new]`
- [ ] Sticky header / back-to-top behaviors `[new]`

## Group F — Dynamic widgets / sidebar
- [ ] Recent posts / Tag cloud / Category list `[new]`
- [ ] Social links `[new]` — privacy-respecting share (no callbacks)
- [ ] Newsletter signup `[new]` — a Mail Form variant
- [ ] Language / skin / theme switchers `[have]` — exist; expose as blocks

## Group G — SEO / head / structured data
- [ ] SEO meta editor `[have]` — built for the CMS head/SEO editor; expose per-page & per-post
- [ ] Open Graph / Twitter cards `[new]`
- [ ] Schema.org / JSON-LD structured data `[new]`
- [ ] XML sitemap + robots `[new]`
- [ ] Canonical / meta-robots controls `[new]`

---

## Deferred / gated
- Commerce blocks (product grid, cart, checkout) — belong to a future **commerce module**, not base themes.

*Keep this in sync with THEMES.md §10 when a group changes the phasing.*
