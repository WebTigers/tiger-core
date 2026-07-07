# Tiger — Media Module (design)

The media subsystem: one `media` table of file metadata, pluggable storage adapters
(filesystem / S3 / GCS / Azure), an upload pipeline with optional virus + AI scanning,
generated variants (thumbnails, PDF/video previews), and an admin **Media Library** that
doubles as a reusable **picker** for other modules. Read this before touching
`library/Tiger/Media/*` or `modules/media/*`.

Reference apps: **JARVIS2** (Tiger-based) — pdf.js for client-side PDF preview, AWS
Rekognition moderation (`PDFContentModerator`), S3+CloudFront storage, thumbnails-as-JSON.
This generalizes those into Core with a storage-adapter abstraction.

---

## 1. The engine/feature split (like the CMS)

- **Engine → `library/Tiger/*` (platform, `@api`):** `Tiger_Model_Media` (the store),
  `Tiger_Media_Storage_*` (adapters + factory), `Tiger_Media_Image` (variants),
  `Tiger_Media_Scanner_*` (virus/AI hooks). Reusable by any app/module, no routes.
- **Feature → `modules/media/*`:** the admin Library UI + the `/api` service
  (`Media_Service_Media`: upload / list / delete / update), the private-file streamer
  controller, ACL, views. Themes/modules consume the engine via the model + a view helper.

## 2. Schema — `media` (migration 0018)

One row per stored file. Tenant- and locale-scoped like `page`.

| Column | Purpose |
|---|---|
| `media_id` | UUID v7 PK |
| `org_id` | tenant scope (`''` = global; tenant wins), same axis as `page`/`config` |
| `locale` | language tag (`en`), for localized assets / captions; `''` = language-neutral |
| `disk` | which configured storage disk holds it (`local`, `s3`, …) |
| `storage_key` | the adapter-relative key/path (e.g. `2026/07/uuid.jpg`) |
| `visibility` | `public` \| `private` |
| `kind` | derived category: `image` \| `document` \| `pdf` \| `video` \| `audio` \| `archive` \| `other` (drives filtering + which variant pipeline runs) |
| `mime_type`, `extension`, `file_size` | as uploaded (bytes) |
| `checksum` | sha256 of the bytes (dedupe / integrity) |
| `width`, `height`, `duration` | image dims / media duration (nullable) |
| `filename` | original upload name (display + download) |
| `title`, `caption`, `alt_text` | editorial + accessibility (portfolio labels, `<img alt>`) |
| `variants` | JSON map of generated derivatives: `{thumbnail:{key,w,h},small:{…},…,pdf_preview:{key}}` |
| `scan_status` | `pending` \| `skipped` \| `clean` \| `infected` \| `rejected` \| `in_review` \| `approved` |
| `scan_meta` | JSON: ClamAV signature, AI scores/labels, async job/review id |
| `sort_order` | manual ordering within a portfolio/collection |
| *standard* | `status`, `deleted`, `created_by`, `updated_by`, `created_at`, `updated_at` |

Indexes: `(org_id, kind, status, deleted)` (library filters), `(checksum)` (dedupe),
`(scan_status)` (moderation queue), FULLTEXT `(filename, title, caption)` (search).

## 3. Storage adapters — `Tiger_Media_Storage_*`

`Tiger_Media_Storage_Interface`:

```
put($key, $sourcePathOrStream, $visibility, $mime): void
get($key): string                       // bytes
stream($key): resource                  // for large files
delete($key): void
exists($key): bool
size($key): int
url($key, $visibility, $ttl = null): string   // public: direct/CDN; private: signed or a
                                              // route to the streamer controller
```

Config (`media.disks.*`, default `media.default_disk`): each disk names an adapter +
settings. `Tiger_Media_Storage::disk($name)` is the factory.

- **`Tiger_Media_Storage_Filesystem`** (built first, no deps). Public files live under the
  docroot (`public/_media/…`, a symlink to a storage root) → direct URL. Private files live
  **outside** the docroot and are served only through the ACL-checked streamer
  (`/media/file/<id>`).
- **`Tiger_Media_Storage_S3`** — real once `aws/aws-sdk-php` is installed (public = CloudFront/
  object URL; private = presigned GET). Absent the SDK, a SigV4 REST fallback is possible
  (cf. the SES SMTP signer) but not the first cut.
- **`Tiger_Media_Storage_Gcs` / `_Azure`** — interface-scaffolded with a clear TODO block for
  an AI/human to fill in against their SDKs; not wired until needed.

## 4. Upload pipeline (`Media_Service_Media::upload`)

Drag-drop posts one multipart file per request (per-file XHR **progress**); the service:

1. **Validate** — size cap + MIME allowlist (`media.max_upload`, `media.allow.*`), derive `kind`.
2. **Virus scan** *(optional, `media.scan.clamav`)* — `Tiger_Media_Scanner_ClamAv` (clamd
   socket). Infected → reject with a message, nothing stored. *(ClamAV is memory-heavy — off
   on small instances; boost the box to test.)*
3. **AI image review** *(optional, `media.scan.image`)* — `Tiger_Media_Scanner_Rekognition`
   (`DetectModerationLabels`); below threshold → store, else reject with a message.
4. **Store** via the disk adapter → compute checksum → **insert the `media` row**.
5. **Variants** — images: `Tiger_Media_Image` (GD) makes thumbnail/small/medium/large; PDFs get
   their preview **client-side via pdf.js** (no server PDF tooling), with an optional server
   poster later; video posters need ffmpeg (later).
6. **Video AI review** *(optional, `media.scan.video`)* — store **private**, `scan_status =
   in_review`, submit `StartContentModeration` (async); an SNS→**webhook** (`/media/callback`)
   flips to `approved`/`rejected` and unlocks visibility.

Scanners share `Tiger_Media_Scanner_Interface`; all default **off** (build the hooks now, plug
scanners in per deploy).

## 5. UI — the Media Library + picker

- **Two views over the same `/api` data:** a **DataTables** list (thumbnail + filename +
  kind + size + scan badge) and a **vanilla CSS-grid Portfolio** (no Isotope — its
  GPL/commercial license clashes with Tiger's permissive stance; plain CSS Grid does the job
  zero-dep) with a shared search + type filter, a size selector (thumbnail / small / medium /
  large) that swaps tile size + variant, image click → **TigerLightbox** gallery, and (P3
  remaining) PDFs rendering a pdf.js first-page preview — a **file-type icon until it paints**.
- **Reusable picker:** the same grid embeds in a modal so any module (CMS page editor, a
  gallery field) can select existing media or upload new — returning `media_id`(s).
- **Drag-drop uploader** with a per-file progress list, then the new tiles stream into the grid.

## 6. Serving & security

Public files: adapter `url()` (direct / CDN). Private files: `MediaController::fileAction`
(`/media/file/<id>`) checks org scope + ACL, then streams from the adapter (or 302s to a
short-lived presigned URL for S3). `scan_status = in_review` keeps a video private until the
webhook approves it.

## 7. Phasing

1. **Foundation** — schema, `media.*` config, `Tiger_Model_Media`, storage interface +
   filesystem adapter + factory, the `media` module (upload / list / delete) + a working
   drag-drop uploader and a DataTables library. *(box prep: raise upload limits, install
   `php-gd`, create the storage roots + public symlink.)*
2. **Image variants** — `Tiger_Media_Image` (GD) thumbnail/small/medium/large on upload.
3. **Portfolio UI** — Portfolio grid (vanilla CSS grid, MIT-clean), size selector, fullsize modal, pdf.js preview, the picker.
4. **Scanning** — `Tiger_Media_Scanner_*`: ClamAV, Rekognition image, then async video +
   the SNS webhook + the moderation queue.
5. **Cloud storage** — the S3 adapter (SDK), then GCS/Azure.

Progress lives in the task list; this doc is the map.
