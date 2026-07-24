# Tiger — Marketplace & Licensing (the open protocol)

How the Module Manager lets an install **buy, install, update, and manage paid modules** from **anyone**,
and how a seller **runs a license authority** to sell them — a decentralized, self-hostable protocol, not
a WebTigers-only service. Read this before touching the pricing schema, the license checker, the signature
gate, or the install-from-authority path. For the platform *why* read [ARCHITECTURE.md](ARCHITECTURE.md);
for the sibling designs read [THEMES.md](THEMES.md) (themes as modules) and [CODE.md](CODE.md); for the
`/api` contract read [WEBSERVICES.md](WEBSERVICES.md).

> **Status.** The **buyer-side client is built** in core (the classes named below): the pricing schema,
> the artifact-signature gate, the license checker, install-from-authority, and the update gate. The
> **directory** (`Vendors`, the free community catalog) ships today. The **seller side** — a running
> *license authority* + a marketplace catalog — is a **separate operator concern**: core defines the
> *protocol* an authority must speak, not a specific authority implementation. Anyone can write one; §7
> is the contract. Where a section says "an operator provides," that's outside core.

---

## 0. The one principle

**Selling is federated; core doesn't gatekeep who sells or police what they sell.** An admin adds the
marketplaces they trust, exactly like adding a package source. Core's job is to make that trust decision
**informed** — *provenance* (who published this), *integrity* (did it arrive unmodified), and *consent* (a
deliberate gate) — never to approve listings. It's open source; there is no central chokepoint.

The corollary that keeps this *safe* rather than reckless: **the mechanism still owes the admin integrity.**
"No gatekeeper" ≠ "no seatbelts." Paid code is **signed**; the installer refuses an unsigned licensed
module; a lapsed license is a **nag, never a disable**.

---

## 1. Two layers: Directory vs. Marketplace

| | **Directory** (built: `WebTigers/Vendors`) | **Marketplace** (an operator-run authority + a catalog) |
|---|---|---|
| Is | a *catalog* — a serverless git repo Tiger fetches + searches | a *store* — transacts, verifies licenses, mints downloads |
| Sells | nothing — it *lists* public modules | public **and private/paid** modules |
| Hosting | one public git repo, no server | a Tiger install running a **license authority** + a manifest repo |
| Count | one (forkable — no chokepoint) | **0..N**, run by anyone |
| Code visibility | public repos only — reviewable | paid modules aren't reviewable → **trust replaces review** |
| Trust | community-curated (bot-reviewed) | **admin-curated** — you add whom you trust |

Both surface in the **same Add screen**. The Directory is the free/discovery tier; the Marketplace layer is
the paid/private tier. State the tradeoff honestly in the UI: the Directory guarantees "code is reviewable";
a paid module **relaxes that to informed trust in the vendor.**

---

## 2. A module declares how it's sold — `module.json` `pricing`

`Tiger_Module_Pricing` is the one interpreter of the block (unknown → `free`; legacy `pro` → `paid`):

```json
"pricing": {
  "model":     "licensed",
  "authority": "https://store.example/license/authority",
  "vendor":    "acme/TigerVendor"
}
```

| `model` | Meaning |
|---|---|
| `free` | no charge |
| `freemium` | free to install, paid upgrade elsewhere |
| `paid` | sold **off**-platform; `pro_url` links out (the platform isn't in the transaction) |
| **`licensed`** | sold + licensed **through** the Module Manager against an `authority` (a URL) run by a `vendor` (an `owner/repo` trust anchor). Update-gated by its authority, and it **must arrive signed.** |

A licensed module stays *clean* — it declares its authority + vendor and nothing more; the core client does
the rest. `Tiger_Module_Pricing::assertValid()` rejects a licensed manifest that omits either field.

---

## 3. The trust anchor — the vendor's `TigerVendor` repo

Connecting to a vendor is pasting a public **`[owner]/TigerVendor`** repo — the vendor's identity, cacheable,
git-native, zero-infra. Its manifest carries:

- **`api_base`** — the authority endpoint (where verify/download happen).
- **`public_key`** — the vendor's Ed25519 public key. **Both** the downloaded artifacts **and** the
  authority's verdicts are signed with the matching secret; the client verifies against this pinned key.
- an optional **catalog** (for browsing) — a marketplace is simply a `TigerVendor` that also ships a catalog;
  a single paid module is a `TigerVendor` with just a key + authority. **Same shape, different richness.**

**Pin on connect.** Show the owner, repo, and **key fingerprint** (`Tiger_Crypto_Signature::fingerprint`) in
a consent gate — "this can serve code that runs on your server; add only if you trust it." A later silent key
change is a takeover signal → warn + re-consent.

---

## 4. Two signatures, two jobs (don't conflate them)

- **Artifact signature** (integrity of *code*) — the vendor signs the release; the installer verifies it
  **before extraction** against the pinned public key. *"Is this code authentic and unmodified?"* **Required**
  for a licensed module.
- **Entitlement signature** (integrity of the *verdict*) — the authority signs its short-TTL verify reply;
  the client verifies + caches it. *"Is this buyer allowed, on this domain?"*

Both are **integrity**, not DRM: enforcement is soft (nag, fail-open, source-available/patchable). **Signing
the message ≠ locking the door.** `Tiger_Crypto_Signature` (detached Ed25519, libsodium) is the primitive for
both; verification is fail-safe (bad/malformed → false, never throws).

---

## 5. The buyer's client (what core provides)

The whole client half is core, free, and **vendor-neutral** — it works against *any* authority:

| Class | Role |
|---|---|
| `Tiger_Module_Pricing` | interpret a manifest's `pricing` (model + authority + vendor) |
| `Tiger_Crypto_Signature` | Ed25519 keypair / sign / verify / `verifyFile` / `fingerprint` |
| `Tiger_License_Checker` | hold the install's license keys, **verify** against a module's declared authority (cached, signed), **`gate()`** auto-update, `remember()` a bought license. Persists in the lazy `option` tier (`Tiger_License_Store`). |
| `Tiger_License_Authority` (client) | the client for an authority's `/download` endpoint — get a signed download descriptor `{url, signature, sha256, version}` |
| `Tiger_Module_Installer::installFromAuthority` | fetch the signed download → **verify the signature before extract** → install → `remember()` the license |
| `Tiger_Update_Checker` + `System_Service_Updates` | annotate a licensed module's update with its license state, and **refuse applying an update** to a definitively lapsed one |

**Nag, never disable** is enforced end-to-end: only a *definitive, reached-home* `lapsed` verdict withholds an
**update** — the installed version keeps running forever; an unreachable/unknown authority **never** blocks
(fail-open), so an authority outage can't brick a fleet.

---

## 6. The lifecycle (the protocol, end to end)

```
CONNECT   paste [owner]/TigerVendor → fetch manifest → pin api_base + public_key → consent gate
BROWSE    the Add screen aggregates the Directory + every connected marketplace's catalog
BUY       a paid listing → a popup WINDOW to the seller's own hosted checkout (NOT an iframe — hosted
          checkout can't be framed, and 3DS breaks in iframes). The seller keeps the money on their own
          Stripe; the buyer's install never sees a card. On success the seller issues a domain-bound
          license key → Tiger_License_Checker::remember() stores it.
INSTALL   installFromAuthority: POST {key, product, domain} to the authority's /download → it verifies the
          license server-side and returns a short-lived SIGNED GitHub asset URL (it never proxies the
          bytes; its repo token never leaves it). The client streams from the CDN, VERIFIES the artifact
          signature against the pinned key, then extracts + installs.
VERIFY    periodically the Checker POSTs {key, domain, product} to the authority's /verify → a signed
          short-TTL verdict {valid, ttl, latest_version}. Cached; a brief outage rides on the last good one.
UPDATE    same three calls, gated: a lapsed license withholds the update ("renew to update"); everything
          else proceeds. Keys off the module's declared authority — a single-module purchase updates
          exactly like a marketplace one.
MANAGE    the buyer self-serves on the seller's site: see seats + which key is on which domain, RESET a
          key (unbind it to move the license), ADD a website (bind a spare key).
```

### 6a. Single-module direct buy — the marketplace is optional
A buyer needn't add a whole marketplace to buy one module. The Add screen's **URL tab** (paste a repo) reads
its `module.json`; if it's `licensed`, the client pulls the vendor's `TigerVendor` for the key and runs the
same buy → license → signed-download → install. **A single module is a marketplace-of-one; a marketplace is a
`TigerVendor` that adds a catalog.** Buying one skips *discovery*, never *trust/license/integrity*.

---

## 7. Running an authority (the operator's contract)

Core defines the *protocol*, not the authority. **Any vendor can run one** — co-located with their own
checkout so payment stays first-party. An authority is any endpoint that speaks:

- **`POST {api_base}/verify`** ← `{key, domain, product}` → `{payload, signature}` where `payload` is the
  JSON `{valid, ttl, latest_version}` and `signature` is Ed25519 over `payload` with the vendor's secret
  (public half in `TigerVendor`). Bind the key to its domain on first use; enforce it after (subdomains free);
  re-check the payment by id.
- **`POST {api_base}/download`** ← `{key, domain, product}` → `{url, signature, sha256, version}` — verify the
  license, then mint a **short-lived signed** release URL (capture the storage host's 302 with the repo token,
  don't proxy the bytes) and return the **publish-time** artifact signature. `402` when not entitled.
- **Issue** keys on payment (idempotently), **decoupled** from the payment processor — hold only a payment/
  subscription *id* you re-check for `paid?/seats`. **Sign each release once at publish** (the artifact
  signature travels with the download; the token never leaves the box).

A conforming authority + a `TigerVendor` repo is all it takes to sell on every Tiger install — no coordination
with WebTigers. (WebTigers runs *an* authority; it isn't privileged. A first-party reference implementation
ships as a separate, optional module.)

---

## 8. Security posture (the honest version)

- **Curation is open; integrity is not optional.** No approval of listings or sellers. Provenance (owner +
  repo + pinned key fingerprint) + integrity (signed artifacts + TLS + CDN) + a deliberate consent gate.
- **Least privilege, three ways:** the buyer never holds the seller's repo token; the seller never sees the
  buyer's card; core holds neither for a third-party sale.
- **Code review is the free tier's promise, not the paid tier's** — stated plainly at connect + install.
- **Nag, never disable.** A lapsed license withholds *updates only*; nothing is disabled — never a kill switch
  pointed at a customer's production (including during an authority outage). `unknown ≠ lapsed`.
- **Key pinning.** A silent `public_key` rotation is a takeover signal → warn + re-consent.

---

## 9. Rejected alternatives (so we don't relitigate)

| Rejected | Why | Chosen instead |
|---|---|---|
| A central registry server / SSO | a chokepoint; couples the ecosystem to one party's uptime | serverless git manifests (`TigerVendor`) + per-authority keys |
| A marketplace **proxies** every download | it becomes the CDN for all updates — cost + a single point of failure | the authority mints a **short-lived signed URL**; the buyer streams from the CDN |
| Ship the seller's repo token to the buyer | leaks a durable secret to every buyer | token stays server-side; only a disposable signed URL crosses |
| **Iframe** the seller's checkout | `X-Frame-Options`/`frame-ancestors` + 3DS break it; drags PCI toward the buyer | popup window to the seller's own hosted checkout |
| A bespoke buyer-token system | reinvents licensing; a second thing to secure | `Tiger_License_Checker` (client) against any authority |
| **Disable** a module on a lapsed license | a kill switch aimed at a customer's prod (incl. our outages) | **nag, never disable** — updates withheld only |
| Replace the built `Vendors` Directory | throws away an antifragile free tier | keep it as free/discovery; add Marketplace as the paid layer |

---

*This document records the marketplace/licensing protocol + the core client that implements it. The protocol
is public on purpose — anyone can run an authority. If you change a decision, update the "why" here in the
same change.*
