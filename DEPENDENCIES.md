# Tiger — third-party library provisioning (beating dependency hell without Composer)

How a module gets a third-party PHP library (AWS SDK for S3, Stripe SDK for billing) or a
front-end lib (Swagger UI) onto **any** host — including locked-down shared hosting (GoDaddy,
BlueHost) with no shell, no Composer, and `exec` disabled — and how every module then *finds* it.

> **Status.** Design of record. The **provisioner + shared store + autoloading** (§4–§6) are being
> built in tiger-core; the **Vendor Library Registry + its pre-built bundles** (§7) are WebTigers
> infra (a curated repo + CI), consumed by the provisioner. Build to this doc.

---

## 1. The problem

A module declares a dependency — Billing needs `stripe/stripe-php`, a storage module needs
`aws/aws-sdk-php`, TigerAPIDocs wants Swagger UI. On a Composer box this is trivial. On the hosts
Tiger explicitly targets (cPanel/shared, [cpanel-hosting-constraint]) there is **no Composer, often no
shell, and `exec`/`proc_open` disabled**. We still have to (a) get the library there, (b) get its
*transitive* dependencies there, and (c) make it autoloadable for any module that wants it.

Part (b) is the hard part. `aws/aws-sdk-php` alone pulls guzzle, promises, psr7, psr/http-message,
jmespath — a naïve "download the GitHub tarball" gets you the SDK and a fatal error.

## 2. The core insight — resolve off-box, ship the result

**Transitive resolution is a build-time concern, not a runtime one.** Composer exists precisely
because constraint-solving a dependency graph is hard (it's effectively a SAT problem). We do **not**
reimplement that on the customer's host. Instead:

- Resolution runs **once, somewhere with a shell** — WebTigers CI, or a module author's machine —
  producing a **pre-resolved, self-contained bundle**: the library + *every* transitive dependency +
  a generated autoloader, flattened into one tree, versioned, checksummed, published as a GitHub
  release asset.
- The shared host downloads **one tarball** and unpacks it. **Zero resolution on the box. No
  dependency hell** — the graph was solved before the tarball existed.

AWS SDK's guzzle/psr7 knot is untangled *once, by us*, not on every cPanel account. This is the same
move as Tiger's own "pre-built vendored ZIP" install track for no-shell hosts — dependency
resolution belongs to the build, the host is a dumb unpacker.

## 3. Three tiers, capability-gated, fail-closed

When a module needs a library, `Tiger_Vendor` provisions it via the **best tier the environment +
the library support**, and **fails closed** — if nothing works, the module degrades (the TigerAPIDocs
JSON-fallback pattern) or the installer prints a clear, actionable message.

| Tier | Precondition | Mechanism | Resolves deps? |
|---|---|---|---|
| **1 · Composer** | Composer binary reachable **and** `exec`/`proc_open` enabled **and** `vendor/` writable | `composer require <pkg>:<constraint>` into the **app's** `vendor/` | Yes — Composer |
| **2 · Pre-built bundle** | no usable Composer; lib is in the Vendor Library Registry | download the vetted, checksummed **pre-resolved** bundle → unpack into the shared store | Yes — at bundle-build time |
| **3 · Raw tarball** | no usable Composer; lib has **no PHP deps** | download the GH source tarball → store (or a module's `assets/` for front-end libs) | N/A |

Selection is automatic and ordered: Composer if usable (most flexible — any Packagist package); else
a registry bundle if one exists; else a raw tarball if the lib is dependency-free; else **degrade or
report**. Never hang an install trying (see §8).

Worked examples:
- **AWS SDK / Stripe SDK** → Tier 1 on a Composer box, **Tier 2** on shared hosting (we ship the
  bundle; their guzzle/psr7 came pre-resolved inside it).
- **Swagger UI** → **Tier 3** everywhere (front-end assets, no PHP deps) — exactly what TigerAPIDocs
  already does by hand; this generalizes it.

## 4. The shared library store

One app-owned directory, beside Composer's `vendor/`:

```
<app root>/
  vendor/          ← Composer owns this (Tier 1 installs land here, autoloaded by Composer)
  vendor-libs/     ← Tiger owns this (Tier 2/3 land here; the no-Composer store)
    aws-sdk-php/   ← one dir per library
      autoload.php ← shipped by a bundle, or generated for a raw lib
      ...
```

- **Host-local + app-owned + gitignored.** Populated on the host by the Manager at install time
  (downloaded from GitHub), and it persists there — the WordPress model (`wp-content/plugins` isn't
  in your app repo; it's installed on the host). It survives `composer update` (it's outside
  `vendor/`) and it's never committed (third-party code, provisioned not authored).
- **Shared + deduped.** Every module that needs `aws-sdk-php` autoloads the *one* copy here.

## 5. Autoloading — how any module *finds* the lib

At bootstrap, `Tiger_Vendor::registerAutoloaders()` (an app `_initVendorLibraries`) walks
`vendor-libs/*/` and registers each library's autoloader, so its namespaces (`Aws\`, `Stripe\`,
`GuzzleHttp\`) are available to **every** module — no per-module wiring.

- A **bundle** ships its own `autoload.php` (Composer's generated autoloader, flattened) → just
  `require` it.
- A **raw lib** has no autoloader → the provisioner generates one from its `composer.json` `autoload`
  block (`psr-4` / `psr-0` / `classmap` / `files`) at install time and writes an `autoload.php`.

Tier 1 libs need nothing here — they're in `vendor/`, already on Composer's autoloader.

## 6. The one-version rule (the honest limit)

You cannot load two major versions of the same namespace in one PHP process — and Composer forbids
it too (one version per package per project). Tiger adopts the same constraint: **one version of a
shared library per install.** The provisioner intersects requesters' constraints; if two modules
demand *incompatible* majors of the same lib, the Manager **surfaces the conflict to the operator**
("Module A needs aws-sdk ^3, Module B needs ^2 — pick one") rather than pretending or attempting
side-by-side loading. No solver, no magic, no silent breakage. This is the pragmatic floor on
dependency hell: we avoid resolution (§2), and where genuine conflicts remain, we *report* them.

## 7. The Vendor Library Registry (WebTigers infra)

Tier 2's source of truth — a curated repo (e.g. `WebTigers/tiger-vendor-bundles`) with, per library:

- a **manifest** entry (name, available versions, namespaces, bundle URL, `sha256`, license), and
- **pre-built bundle assets** on GitHub Releases — each a self-contained, pre-resolved tree built by
  CI: `composer require <pkg>` off-box → flatten `vendor/` + the generated autoloader → tarball →
  `sha256` → publish.

It starts **small and sufficient**: the handful of SDKs a SaaS actually needs — AWS, Stripe, Guzzle,
PHPMailer. Curation *is* the feature: it's the supply-chain trust boundary (like the module
registry), so a host never fetches an unvetted, unpinned, unbounded dependency.

## 8. Security & the sharp edges

- **Vetted, pinned, checksummed.** Bundles come from the registry (curated), pinned to an exact
  version, and the download is **`sha256`-verified** before it's trusted. Raw tarballs pin a tag +
  checksum. No arbitrary/unbounded fetch.
- **"Composer available" is more than a binary.** Shared hosts often *have* Composer but **disable
  `exec`/`proc_open`**, or have a read-only `vendor/`, or tight memory/time. `Tiger_Vendor_Environment`
  checks all of it and **fails closed** to Tier 2/3 — never hangs an install shelling out to a
  Composer that can't run.
- **CLI auto, web advise.** `bin/tiger module:install` runs Composer directly (real shell, no web
  timeout). The **web installer** never runs heavy Composer synchronously inside a request — it uses
  Tier 2/3, or detects Composer and *advises* the operator (copy-paste `composer require`), or queues
  it. Provisioning downloads are size/time-capped and streamed to disk.
- **Idempotent + atomic.** Install to a temp dir, verify, then atomically swap into `vendor-libs/`;
  a half-download never leaves a broken lib.

## 9. module.json — declaring dependencies

```json
"dependencies": {
  "php": [
    {
      "name":       "aws/aws-sdk-php",
      "constraint": "^3.0",
      "namespaces": ["Aws\\"],
      "bundle":     "https://github.com/WebTigers/tiger-vendor-bundles/releases/download/aws-sdk-php/3.300.0.tar.gz",
      "sha256":     "…",
      "optional":   false
    }
  ],
  "asset": [
    {
      "name":    "swagger-ui",
      "tarball": "https://github.com/swagger-api/swagger-ui/archive/refs/tags/v5.17.14.tar.gz",
      "include": ["dist/swagger-ui.css", "dist/swagger-ui-bundle.js"],
      "target":  "assets/vendor/swagger-ui",
      "optional": true
    }
  ]
}
```

- **`php`** deps → provisioned via the tiers (§3), autoloaded from the store (§5). `bundle` is the
  Tier-2 hint; Tier 1 uses `name`+`constraint`; a dep-free lib can Tier-3 from its repo tarball.
- **`asset`** deps → front-end, fetched raw into the module's own `assets/` (Tier 3). This is exactly
  TigerAPIDocs' Swagger UI, formalized.
- **`optional: true`** → absence *degrades*, never blocks install/activation (the TigerAPIDocs
  pattern). `false` → a missing, un-provisionable dep is an install error with guidance.

## 10. What's built vs. staged

- **Built in tiger-core:** `Tiger_Vendor_Environment` (capability detection), the shared store +
  bootstrap autoloading, and `Tiger_Vendor` provisioning (Tier 1 Composer exec; Tier 2/3 download →
  verify → unpack → autoloader-generate). The `module.json` `dependencies` schema + the installer
  hook.
- **WebTigers infra (staged):** the Vendor Library Registry repo + the CI bundle-builder + the
  published AWS/Stripe/Guzzle bundles. The provisioner *consumes* these; it doesn't build them.
- **Follow-on:** rewire TigerAPIDocs' Swagger UI to an `asset` dependency; the Billing module's
  Stripe SDK as the first real Tier-2 consumer; conflict-reporting UI (§6).

---

*The whole trick: never solve a dependency graph on a shared host. Solve it once, off-box, freeze the
result into a checksummed bundle, and make the host a dumb unpacker with a shared autoloader. Composer
where you can, pre-resolved bundles where you can't, raw tarballs where there's nothing to resolve —
and an honest error where two modules truly disagree.*
