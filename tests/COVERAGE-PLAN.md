# Tiger — Test Coverage Manifest (TEMPORARY / WORKING DOC)

> **Purpose.** A comprehensive, class-by-class inventory of untested code across the public
> **WebTigers/Tiger*** repositories, to drive writing a test suite toward **near-100% coverage**.
> This pairs with the `tiger-core/BACKLOG.md` "Issues / tech debt" item: *"Automated test suite +
> CI — none yet for Tiger's own code (gates 1.0)."*
>
> **Status:** inventory generated 2026-07-18; **harness promoted to `main` + CI wired 2026-07-24** (this
> doc now lives in the repo). The blocking prerequisite in §3 is **DONE** — `phpunit.xml`, `tests/bootstrap.php`,
> the `Tiger\Tests\Support\{Unit,Integration}TestCase` bases, the `composer test:*` scripts, and the
> `.github/workflows/tests.yml` matrix (unit PHP 8.1–8.5 + one integration job on MariaDB) are all live.
>
> **Coverage as of 2026-07-24 (17 test files — the two previously-disjoint sets, now unioned on `main`):**
> - **Unit — 128 tests / ~10.3k assertions:** `Tiger_Acl_Acl`, `Tiger_Ajax_ServiceFactory`, `Tiger_Auth_Totp`,
>   `Tiger_Crypto`, `Tiger_Security`, `Tiger_Uuid`, `Tiger_Crypto_Signature`, `Tiger_License_{Authority,Checker}`,
>   `Tiger_Module_{Pricing,Compat,Dependency,Installer(signature)}`, agent attachment/loop.
> - **Integration — 8 tests (real MariaDB):** `Tiger_Model_OrgUser`, schema+model migrate-up smoke.
>
> **Still to write (the bulk):** the priority spine in §5/§8 — the P1 crypto/auth models, the model/tenant
> DB layer (§6.1), the kernel P1s (§6.2), media/storage adapters (§6.3), the module `/api` services (§6.4),
> and the satellite modules (§6.5). **New since the 2026-07-18 scan (add to the inventory as covered):** the
> licensing/marketplace client (`Tiger_License_*`, `Tiger_Crypto_Signature`, `Tiger_Module_{Pricing,Compat}`),
> TigerAgent's attachment layer, and the commerce module repos (TigerStripe/Membership/Shop/Download/List/
> Roundtable/License — each its own repo + harness). This file is the running tracker for that backfill.

---

## 1. Scope & filters

Rule applied: **public repos only, `Tiger*`-family only, private repos excluded.** Enumerated from
`gh repo list WebTigers` (18 repos total).

| Repo (GH) | Local dir | Lang | In scope? | Why |
|---|---|---|---|---|
| **WebTigers/TigerCore** | `tiger-core` | PHP (196 first-party .php) | ✅ **primary** | the framework — kernel, substrate, modules; **0 tests** |
| **WebTigers/TigerDocs** | `TigerDocs` | PHP (12) | ✅ | docs engine + reference generator; 0 tests |
| **WebTigers/TigerShield** | `TigerShield` | PHP (20) | ✅ | BSD WAF — block/rate-limit decisions; 0 tests |
| **WebTigers/TigerAPIDocs** | `TigerAPIDocs` | PHP (3) | ✅ | Swagger UI module; 0 tests |
| **WebTigers/TigerCodeSnippets** | `TigerCodeSnippets` | PHP (6) | ✅ | 6 pure helper *functions* — ideal easy units; 0 tests |
| **WebTigers/TigerVendors** | `Vendors` | PHP (3) | ✅ (partial) | registry review/compile scripts; 0 tests |
| **WebTigers/Tiger** | `Tiger` | PHP (5) | ✅ (thin) | app skeleton — Bootstrap/index/custom; mostly glue |
| **WebTigers/tiger-install** | `tiger-install` | PHP (1) | ✅ (thin) | installer; 0 tests |
| **WebTigers/TigerUpload** | `TigerUpload` | JS | ✅ (JS suite) | headless upload engine; separate JS tooling |
| **WebTigers/TigerLightbox** | `TigerLightbox` | JS | ✅ (JS suite) | lightbox/gallery; separate JS tooling |
| **WebTigers/TigerZF** | `TigerZF` | PHP (8957) | ⚠️ **special case** | see §2 — already has 1,210 test files / upstream ZF1 baseline |
| WebTigers/TigerSponsors | `Sponsors` | JSON | ❌ | ranks-only data repo, no classes |
| WebTigers/theme-grey-mist | `theme-grey-mist` | HTML/CSS | ❌ | a theme — 0 PHP classes (view scripts only) |
| WebTigers/tiger-vendor-bundles | `tiger-vendor-bundles` | Shell | ❌ | CI bundle-builder scripts, no PHP classes |
| WebTigers/tigerDOM | *(not cloned)* | JS (2022) | ❌ | superseded by `tiger.dom.js` in the PUMA theme; dead |
| WebTigers/TigerDocs-content | *private* | — | ❌ | **private repo — excluded by rule** |
| WebTigers/AskLevi | *private* | — | ❌ | **private — excluded** (reference app, not shipped) |
| WebTigers/DonnaAI | *private* | — | ❌ | **private — excluded** |

Note: local `TigerCore` and `tiger-core` are **two checkouts of the same remote** (WebTigers/TigerCore).
Canonical working copy = `tiger-core`; the `TigerCore` dir is a stale duplicate and is ignored.

---

## 2. TigerZF — the special case (already covered)

TigerZF ships **1,210 `*Test.php` files** (the upstream `Shardj/zf1-future` baseline, ~10,796 tests
/ 359,814 assertions on PHP 8.5). `library/` contains **only `Zend/`** — WebTigers added **no net-new
classes**; the modernization was *fixes to existing Zend_\* classes*, which the existing suite
exercises (current baseline: 3 failures, all host-environment artifacts, not real regressions).

**Verdict: excluded from the "needs tests written" scope.** It is not untested. The only follow-on
work here is keeping the matrix green, not authoring new tests. *(If WebTigers ever adds a genuinely
new `Zend_*`/`Tiger_*` class into this repo, it lands in this manifest then — none exist today.)*

---

## 3. The blocking prerequisite (do this before writing a single test)

**None of the in-scope repos has a PHPUnit harness.** tiger-core has no `phpunit.xml`, no `tests/`
dir, no `TestConfiguration`, no CI workflow for its own code. So step zero is standing up the harness,
and it is a real design task because of Tiger's shape:

- **Bootstrap/autoload** — PSR-0 underscore `Tiger_*` + `Zend_*` from `vendor/`; a test bootstrap must
  wire the include path the same way `Tiger_Application` does, without running a full web bootstrap.
- **DB-touching classes need a fixture DB** — models (`Tiger_Model_*`), `/api` services (validate →
  transaction), and the migrator. Decide: a throwaway MySQL schema via the migrations, an in-memory
  SQLite shim (risky — models use `Zend_Db_Expr`, `NOW()`, `FULLTEXT`, JSON columns; SQLite will
  diverge), or a transactional-rollback-per-test pattern against a real MySQL. **Recommend real MySQL
  + migrate-once + per-test transaction rollback.**
- **Static registries** (`Tiger_Routing_Overrides`, `Tiger_Admin_Settings`, `Tiger_Code_Modules`,
  `Tiger_Cms_Renderer` shortcodes) hold process state — tests must reset them between cases.
- **Crypto/secrets** — `Tiger_Crypto`/`Tiger_Security` need a test key + pepper (not the dev ones).
- **CI** — mirror TigerZF's matrix (PHP 8.1–8.5) once green; it's the gate for the 1.0 `@api` freeze.

CI/harness gating is tracked separately in memory ([[tiger-core-release-gating]] — main is already
PR-gated on a smoke test); this manifest is the *unit-coverage* companion to that.

---

## 4. Classification rubric (how to read the tables)

Each class row: `class | path | kind | public methods | testability | priority | sec | note`

- **kind** — model · service · controller · form · plugin · helper · value · factory · adapter ·
  abstract · interface · trait · console · script
- **testability** — the dominant harness need:
  - `pure` (no deps — write these first, cheapest coverage) · `static` (static state, reset between
    tests) · `db` (fixture DB) · `http` (request/response doubles) · `fs` (filesystem/temp dirs) ·
    `net` (external SDK/HTTP — mock or contract-test) · `crypto` (test key/pepper)
- **priority** — **P1** security/data-integrity-critical · **P2** core substrate/kernel · **P3**
  feature module · **P4** glue/trivial
- **sec** — `yes` if a bug is a security or tenant-isolation/data-integrity risk

### Coverage-math honesty
"Near-100%" should be measured against **testable logic**, not raw line count. Realistically:
- **Controllers** (`*Action`, render the shell) and **view scripts** — thin by design (ADMIN.md:
  "thin controllers, fat services"). Cover via a few dispatch/smoke tests; don't chase per-line.
- **Migrations** (`migrations/NNNN_*.php`, ~31 files) — return `['up','down']` arrays, not classes.
  Cover by a single **"migrate up on a clean DB, then down"** integration test, not per-file.
- **Bootstraps** / near-empty glue — smoke only.
- The **coverage budget belongs on** `Tiger_Model_*`, `Tiger_Service_*`, `Tiger_Acl_*`,
  `Tiger_Ajax_ServiceFactory`, auth/crypto, `Tiger_Module_Installer`, storage adapters, and the module
  `/api` services — the P1/P2 logic.

---

## 5. Priority spine (write tests in this order)

From `BACKLOG.md` ("security-critical paths first") + the audit:

1. **`/api` gateway guard** — `Tiger_Ajax_ServiceFactory`: reserved-module refusal (`tiger`/`zend`/
   `core`/…), class-name sanitization (`[a-zA-Z]` strip, no path traversal), `extends
   Tiger_Service_Service` type guard, deny-by-default ACL.
2. **ACL** — `Tiger_Acl_Acl`: deny-by-default, role-on-membership resolution, `explain()`, inheritance.
3. **Auth/session** — login + lockout, `auth_challenge` issue/redeem (single-use/TTL/attempt cap),
   password history/pepper, TOTP vectors, session TTL tiers.
4. **Crypto** — `Tiger_Crypto`/`Tiger_Security`: encrypt/decrypt round-trip, pepper HMAC, key/pepper
   **rotation** (retired-secret fallback), constant-time verify.
5. **Module install** — `Tiger_Module_Installer`: **zip-slip guard**, checksum verify, pinned-ref.
6. **Vendor/update** — `Tiger_Vendor` + `Tiger_Update_Core`: download→verify→atomic swap→rollback.
7. **Tenant isolation** — `Tiger_Model_Table` (UUID mint, actor stamp, soft-delete `activeSelect`),
   org-scoping in models, storage-key traversal guards, private-file visibility.
8. **Code execution** — `Tiger_Code_Runtime` compile/lint gate; `Code_Service_Code` superadmin gate.
9. **Input validation** — `Tiger_Validate_*`, `Tiger_Form::convenienceValidate`.
10. Everything P2, then P3 features, then P4 smoke.

---

## 6. Class inventory (per repo)

> Filled from six parallel read-only scans. Rows are `class | path | kind | #pub | testability |
> priority | sec | note`.

### 6.1 tiger-core — library/Tiger/Model, Db, Validate, Policy  *(data layer)*  — 35 classes

**P1 / security-critical (write first):**

| class | path | kind | #pub | test | note |
|---|---|---|---|---|---|
| Tiger_Model_UserCredential | Model/UserCredential.php | model | 24 | crypto | ⚠️ durable auth factors — `verifyPassword` pepper-migration rehash, `verifyToken`/`redeemRecovery` constant-time, lockout counter, TOTP encrypt-at-rest + rekey |
| Tiger_Model_Table | Model/Table.php | abstract | 10 | db | ⚠️ **base gateway** — UUID mint, actor + `org_id` tenant stamp, soft-delete; `activeSelect()` deleted-scoping, `findById` exclusion. *Every model inherits this — test it hardest.* |
| Tiger_Model_OrgUser | Model/OrgUser.php | model | 4 | db | ⚠️ membership = tenancy boundary AND role carrier; `membership()`/`roleOf()` null-means-deny — a wrong hit crosses tenants or grants a role |
| Tiger_Model_AuthChallenge | Model/AuthChallenge.php | model | 6 | db | ⚠️ single-use hashed OTP/reset; `redeem()` expiry + attempt-lock + consume ordering + timing-safe match |
| Tiger_Model_User | Model/User.php | model | 4 | db | ⚠️ `findByIdentifier()` — only **verified** factors resolve identity; `isTaken` uniqueness |
| Tiger_Model_Code | Model/Code.php | model | 9 | db | ⚠️ executable-PHP store (RCE surface) — `lint()` gate + server-lang platform-scope restriction before a row goes active |
| Tiger_Policy_Password | Policy/Password.php | helper | 4 | db | ⚠️ per-org policy — `_isReused` across current+retired hashes over the pepper boundary + min-length/complexity/expiry |
| Tiger_Validate_Password | Validate/Password.php | helper | 1 | db | ⚠️ form validator wrapping the policy; must not pass a weak/reused password |
| Tiger_Validate_Recaptcha | Validate/Recaptcha.php | helper | 1 | net | ⚠️ server-side verify — fail-open-on-outage policy + v3 score threshold + action-replay + disabled pass-through |
| Tiger_Model_PasswordHistory | Model/PasswordHistory.php | model | 3 | db | ⚠️ reuse-prevention — `prune()` bound + `recentForUser` ordering feeding policy |
| Tiger_Model_AclResource | Model/AclResource.php | model | 1 | db | ⚠️ `activeSelect` excludes deleted/inactive resources correctly |
| Tiger_Model_AclRole | Model/AclRole.php | model | 1 | db | ⚠️ correct active-role set feeding inheritance |
| Tiger_Model_AclRule | Model/AclRule.php | model | 1 | db | ⚠️ a dropped/extra rule flips an authorization decision |

**P2 / substrate:**

| class | path | #pub | test | note |
|---|---|---|---|---|
| Tiger_Db_Migrator | Db/Migrator.php | 3 | db | ⚠️ version ordering + apply-only-after-all-succeed idempotency |
| Tiger_Model_Page | Model/Page.php | 6 | db | ⚠️ `resolveBySlug` org cascade/publish gate + phtml trusted-code handling |
| Tiger_Model_Media | Model/Media.php | 13 | db | ⚠️ `url()` public-direct vs private-ACL-streamer routing + `classify()` extension allowlist |
| Tiger_Model_Org | Model/Org.php | 6 | db | ⚠️ `siteOrgId()` cache/founding-org heuristic + `slugTaken` |
| Tiger_Model_Login | Model/Login.php | 6 | db | ⚠️ `recentFailures*` feeding rate-limit/lockout |
| Tiger_Model_Session | Model/Session.php | 3 | db | ⚠️ `gc()` expiry math + `deleteByUserId` force-logout |
| Tiger_Model_Config | Model/Config.php | 3 | db | scope/scope_id upsert isolation |
| Tiger_Model_Module | Model/Module.php | 6 | db | `inactiveSlugs()` boot gate stripping controller dirs |

**P3/P4 (thinner):** Tiger_Model_Option (5,db), Tiger_Model_Menu (8,db — reorder ownership guard),
Tiger_Model_Translation (3,db), Tiger_Model_PageVersion (4,db — `nextVersion` MAX+1 race),
Tiger_Model_CodeVersion (4,db, same race), Tiger_Model_PageRedirect (3,db — redirect-loop clear),
Tiger_Model_Address (1,db), Tiger_Model_Contact (0,db), Tiger_Model_OrgAddress/OrgContact/UserAddress/UserContact
(1 each,db — link finders), Tiger_Model_UpdateHistory (2,db), Tiger_Model_MessageObject (1,static),
Tiger_Model_ResponseObject (0,pure — DTO).

### 6.2 tiger-core — library/Tiger kernel  — 60 classes (59 first-party + 1 vendored)

**P1 / security-critical (the spine — §5 order lives mostly here):**

| class | path | #pub | test | note |
|---|---|---|---|---|
| Tiger_Service_Authentication | Service/Authentication.php | 26 | db | ⚠️ **the auth engine** — login/logout, reset, 2FA/TOTP enroll+verify, recovery, lock, org switch, token identity. Timing-safe login (dummy hash), 2FA verify, reset challenge, lock flow |
| Tiger_Ajax_ServiceFactory | Ajax/ServiceFactory.php | 4 | http | ⚠️ **/api dispatcher** — reserved-module guard, alpha-only segment→class sanitization, ACL authorize BEFORE instantiation, deny-by-default. Test reserve-guard bypass + param sanitization + authorize order |
| Tiger_Acl_Acl | Acl/Acl.php | 2 | db | ⚠️ ACL engine — deny-by-default, ini-chain + DB rule load, `explain`, role chain, wildcards. Override order + wildcard + topological role add |
| Tiger_Controller_Plugin_Authorization | Controller/Plugin/Authorization.php | 1 | http | ⚠️ deny-by-default on **every** preDispatch (resource = controller class); role resolve + deny redirect + module-qualified resource |
| Tiger_Security | Security.php | 6 | crypto | ⚠️ password prehash+pepper, code hash/verify, pepper rotation keyring; timing-safe `codeMatches` |
| Tiger_Crypto | Crypto.php | 5 | crypto | ⚠️ envelope encrypt/decrypt/reencrypt keyring; key rotation + tamper/decrypt-failure |
| Tiger_Auth_Totp | Auth/Totp.php | 6 | crypto | ⚠️ TOTP gen/verify + base32; drift window + code-at-counter (RFC vectors) |
| Tiger_Session_SaveHandler_DbTable | Session/SaveHandler/DbTable.php | 2 | db | ⚠️ DB session persistence, per-role TTL, identity binding on write; gc lifetime |
| Tiger_Module_Installer | Module/Installer.php | 7 | fs | ⚠️ install from url/upload/tarball — **zip-slip guard**, slug validate, `checkRequires`, publish, migrate |
| Tiger_Update_Core | Update/Core.php | 4 | net | ⚠️ resolve+download+extract core release, maintenance flag, **vendor swap** — download verify + zip-slip + rollback/health |
| Tiger_Vendor | Vendor.php | 7 | net | ⚠️ dep installer — tarball download + **sha256 verify** + extract + autoloader gen + semver satisfies |
| Tiger_Update_Composer | Update/Composer.php | 2 | fs | ⚠️ `composer update` via shell exec — command construction + timeout + failure log |
| Tiger_Install | Install.php | 7 | db | ⚠️ owner creation + secret/storage provisioning + secret rotate/drop; rotate integrity + local.ini writes |
| Tiger_Code_Runtime | Code/Runtime.php | 8 | fs | ⚠️ compiles + includes user PHP (client+server bundles), lint gate, shutdown guard — lint-bypass + arbitrary-code inclusion |
| Tiger_Service_Token | Service/Token.php | 3 | db | ⚠️ API token create/list/revoke — ownership scoping to current user |
| Tiger_Routing_Overrides | Routing/Overrides.php | 4 | static | ⚠️ override registry — `_isReserved` prefix collision (api/auth/admin) + mca parse |
| Tiger_Service_Validate | Service/Validate.php | 1 | pure | ⚠️ builds `Module_Form_Name` from params — module/name sanitization + Tiger_Form-subclass check |

**P2 / core kernel:**

| class | path | #pub | test | note |
|---|---|---|---|---|
| Tiger_Application | Application.php | 2 | fs | ⚠️ boot/run, config build, proxy normalize, update gate — maintenance gate + forwarded-header trust |
| Tiger_Application_Bootstrap | Application/Bootstrap.php | 0 | db | ⚠️ resource init order + DB secret resolution |
| Tiger_Service_Service | Service/Service.php | 1 | db | ⚠️ service base — param b64 decode, dispatch, txn wrapper, admin gate, datatables; `_dispatch`/`_isAdmin`/`_decodeB64` |
| Tiger_Form | Form.php | 2 | pure | ⚠️ base form — element build, CSRF default, translate, convenienceValidate; CSRF token lifetime |
| Tiger_Application_Resource_Modules | Application/Resource/Modules.php | 1 | fs | ⚠️ activation gate — skips inactive/missing so a bad module can't brick boot |
| Tiger_Controller_Admin_Action | Controller/Admin/Action.php | 1 | http | ⚠️ admin base — enforces admin ACL in init |
| Tiger_Controller_Action | Controller/Action.php | 1 | http | base controller init + `_json` |
| Tiger_Update_Checker | Update/Checker.php | 5 | net | update availability + changelog slice, file-cached |
| Tiger_Module_Github | Module/Github.php | 6 | net | ⚠️ GitHub client — parseRepo, fetchRaw, latestRef, tarball download-to-file |

**P3/P4 (features, helpers, glue, view helpers):** Tiger_Recaptcha (12,http,⚠️ failOpen+score),
Tiger_Mail (8,net,⚠️ header injection), Tiger_Location (9,net,⚠️ secret encrypt/decrypt), Tiger_Theme
(7,fs,⚠️ `page(slug)` traversal), Tiger_Cms_Renderer (3,pure,⚠️ shortcode injection/escaping),
Tiger_Code_Modules (8,fs), Tiger_Menu (2,db), Tiger_Log (6,fs), Tiger_Dashboard (6,static),
Tiger_OpenApi_Generator (3,fs), Tiger_Generator_Module (1,fs), Tiger_Vendor_Environment (8,fs),
Tiger_Module_Registry (6,net), Tiger_Module_Discovery (1,fs), Tiger_Module_Dependency (3,fs),
Tiger_Service_Location (3,net), Tiger_I18n_Country (6,pure), Tiger_Uuid (5,pure — v7 monotonic + isValid),
Tiger_Sitemap (3,static), Tiger_Admin_Nav (3,static), Tiger_Admin_Settings (3,static), Tiger_Version
(0,pure), the 4 Controller plugins ThemeContent/LocalePrefix/PageDispatch/RouteOverride (1 each,http),
5 View helpers (Asset/Menu/CodeInject⚠️/MediaField/FormRecaptcha), Tiger_Form_Element_Recaptcha.

**⛔ Vendored third-party (exclude from our coverage math):** `Parsedown` (Cms/vendor/Parsedown.php) —
upstream lib; at most a **characterization test** of safe-mode/XSS-escape as *we* configure it, not
line coverage.

### 6.3 tiger-core — library/Tiger/Media + Location  *(subsystems)*  — 18 classes

| class | path | kind | #pub | test | pri | sec | note |
|---|---|---|---|---|---|---|---|
| Tiger_Media_Storage_S3 | Media/Storage/S3.php | adapter | 9 | net | **P1** | ⚠️ | presigned-GET TTL + `_fullKey` `..` traversal guard + public/private prefix + public-read ACL |
| Tiger_Media_Storage_Gcs | Media/Storage/Gcs.php | adapter | 9 | net | **P1** | ⚠️ | V4 signed URL + `_fullKey` traversal guard + public/private prefix mapping |
| Tiger_Media_Storage_Azure | Media/Storage/Azure.php | adapter | 9 | net | **P1** | ⚠️ | SAS token signing + `_blob` `..` guard + one-vs-two-container visibility |
| Tiger_Media_Storage_Filesystem | Media/Storage/Filesystem.php | adapter | 9 | fs | **P1** | ⚠️ | `_path` `..`/backslash traversal guard + public(docroot)-vs-private(outside) root split; private `url()`='' |
| Tiger_Media_Scan | Media/Scan.php | service | 2 | fs | **P1** | ⚠️ | upload scan orchestrator (virus→image); fail-open on scanner error + infected/rejected→block mapping + config gating |
| Tiger_Media_Scanner_ClamAv | Media/Scanner/ClamAv.php | adapter | 1 | fs | **P1** | ⚠️ | `escapeshellarg` on path + exit-code 0/1/2 → clean/infected/error |
| Tiger_Location_Adapter_Aws | Location/Adapter/Aws.php | adapter | 6 | net | **P1** | ⚠️ | inline **SigV4** canonical-request/string-to-sign/signing-key + security-token path |
| Tiger_Media_Scanner_Rekognition | Media/Scanner/Rekognition.php | adapter | 3 | net | P2 | ⚠️ | AI moderation threshold/MinConfidence + any-label→reject; degrades w/o SDK |
| Tiger_Media_Storage | Media/Storage.php | factory | 3 | pure | P2 | no | disk-name→memoized adapter; unknown-adapter throw + `default_disk` fallback |
| Tiger_Location_Adapter_Abstract | Location/Adapter/Abstract.php | abstract | 9 | http | P2 | no | adapter base: config, capability gating, curl-JSON helper; `_getJson` non-2xx→null degrade |
| Tiger_Media_Image | Media/Image.php | helper | 3 | fs | P3 | no | GD variants (contain, never upscale, EXIF-orient); never-upscale skip + MIME→GD-type map |
| Tiger_Location_Adapter_IpApi | Location/Adapter/IpApi.php | adapter | 4 | net | P3 | no | ip-api.com IP geo (dev default); `rawurlencode` URL + status!='success'→null |
| Tiger_Location_Adapter_Nominatim | Location/Adapter/Nominatim.php | adapter | 6 | net | P3 | no | OSM search+reverse; country-bias querystring + address→Place mapping |
| Tiger_Location_Place | Location/Place.php | value | 2 | pure | P3 | no | normalized place payload; ctor filters unknown keys, `toArray` drops raw blob |
| Tiger_Media_Scanner_Interface | Media/Scanner/Interface.php | interface | 1 | pure | P3 | no | scanner contract (never throws); signature only |
| Tiger_Location_Adapter_Interface | Location/Adapter/Interface.php | interface | 5 | pure | P4 | no | provider capability contract + CAP_* consts |
| Tiger_Media_Storage_Interface | Media/Storage/Interface.php | interface | 8 | pure | P4 | no | storage-backend contract |
| Tiger_Location_Exception | Location/Exception.php | value | 0 | pure | P4 | no | marker exception; no behavior |

**Section note:** the 4 storage adapters share one traversal-guard + visibility contract — write a
**single shared adapter test-trait** exercising `..`/backslash keys, public-vs-private URL shape, and
(for cloud) signed-URL expiry, then run it against each. SigV4/SAS/V4 signing wants **known-answer
vectors** (canonical request → signature), not live calls. `net` adapters get contract tests + one
optional live smoke gated on creds.

### 6.4 tiger-core — core/ + modules/*  — 67 classes across 10 areas

Most controllers are thin (render + admin-gate) → dispatch/smoke tests. The **`/api` services** hold
the logic and carry the P1 weight (validate → transaction, server-computed ACL flags, user-input writes).

**P1 / security-critical services (the real test surface here):**

| class | area | path | #pub | test | note |
|---|---|---|---|---|---|
| System_Service_Modules | system | services/Modules.php | 6 | fs | ⚠️ activate/deactivate/install(GitHub URL)/upload(zip) — installs **UNTRUSTED module code**; gate, upload validation, `is_uploaded_file`/zip checks, RCE-via-installed-module |
| Code_Service_Code | code | services/Code.php | 7 | db | ⚠️ save+activate **lint→`Tiger_Code_Runtime::rebuild()` compiles/executes** community PHP; RCE via lint bypass, redeclare conflict, `module:` toggle |
| Signup_Service_Signup | signup | services/Signup.php | 2 | db | ⚠️ **GUEST-allowed** — builds org+user+credential+addr+contact in one txn from unauth input + email challenge; unauthenticated mass-creation, password set, single-use token redemption |
| System_Service_Updates | system | services/Updates.php | 3 | fs | ⚠️ apply mutates installed code + records + rollback; `applyOne`, rollback detection |
| System_Service_Dashboard | system | services/Dashboard.php | 3 | db | ⚠️ per-user `saveLayout`/`widgetBody` — arbitrary widget render |
| System_Service_Acl | system | services/Acl.php | 2 | db | ⚠️ `simulate()`/`catalog()` server-computed ACL decisions — decision correctness |
| Media_Service_Media | media | services/Media.php | 4 | fs | ⚠️ upload/update/delete — classify+mime-sniff, image variants, ACL flags; gate + upload + variant/path write |
| Cms_Service_Page | cms | services/Page.php | 6 | db | ⚠️ page save/saveDesign/delete/restore + `forkTheme`; gate + page-HTML write + theme fork FS |
| Cms_Service_Menu | cms | services/Menu.php | 5 | db | ⚠️ menu datatable/save/delete/reorder + ACL flags; nested-menu writes |
| Cms_Service_Settings | cms | services/Settings.php | 1 | db | ⚠️ save site settings; gate + write |
| Blog_Service_Post | blog | services/Post.php | 4 | db | ⚠️ save/delete/restore + version snapshot + 301 slug redirect; term/slug/HTML write |
| Access_Service_User | access | services/User.php | 3 | db | ⚠️ datatable/save/delete + per-row ACL flags; gate + user-input write |
| Access_Service_Org | access | services/Org.php | 3 | db | ⚠️ save/delete + slugify + ACL flags; parent-self guard + input write |
| Media_Service_Settings | media | services/Settings.php | 1 | db | ⚠️ save storage/preset settings |
| System_Service_Settings | system | services/Settings.php | 2 | db | ⚠️ global settings + `locationTest()` |
| Identity_Service_Identity | identity | services/Identity.php | 1 | db | ⚠️ save brand (logo/favicon media ids, site name) |

**P1/P2 controllers worth more than smoke:** `AuthController` (core, 12 actions — recaptcha gate +
reset/otp token redemption), `ApiController` (core — ACL routing to ServiceFactory + discovery gating),
`Signup_IndexController` (verify-token landing), `Media_FileController` (**private-file stream — path
traversal + access scoping**), `Media_CallbackController` (upload-completion). `AdminController` (core —
per-user widget layout).

**P2 models / renderers:** `Blog_Model_Post` (7,db — meta pack + save over Tiger_Model_Page),
`Blog_Model_Taxonomy` (8,db — syncPage + slugify), `PageController` (core — `_injectBefore` fragment
injection).

**seo module (already partly built — note for the TigerSEO scope doc):** `Seo_Service_Head` (static
`forRow()` builds meta/OG/robots), `Seo_Service_Schema` (static JSON-LD site/breadcrumb/article),
`Seo_Plugin_Head` (preDispatch inject), `Seo_RobotsController` + `Seo_SitemapController` (P3). These are
`static`/`pure` → easy to unit-test, and cover the head-injection foundation.

**P3/P4 (forms = pure element schemas; Bootstraps = _init glue):** all `*_Form_*` (Access/Blog/Cms/Code/
Identity/Media/System/Signup — pure, except `Access_Form_Org` + `Signup_Form_Signup` do DB-uniqueness →
db), the thin admin render controllers (Access/Blog/Cms/Code/Identity/Media/System list+edit),
`ErrorController` (P4), `IndexController` (core marketing, P3), `Identity_Plugin_Favicon` (P4).

**Non-class in scope (not tested as units):** 5 `Bootstrap.php` that are empty/`_init`-only
(access/signup/code + the registering ones), and **16 language files** (`languages/*/*.php` return
arrays) — cover indirectly via a "translation keys resolve" smoke test, not per-file.

### 6.5 Satellite PHP modules  — 33 classes + 3 P1 procedural files

**TigerShield (WebTigers/TigerShield) — 14 classes; the WAF, so P1-dense**

| class | path | kind | #pub | test | pri | sec | note |
|---|---|---|---|---|---|---|---|
| Tigershield_Plugin_Firewall | plugins/Firewall.php | plugin | 1 | http | **P1** | ⚠️ | front-controller allow/deny entrypoint (routeStartup); `_decide`/`_loginDecision`/`_rateLimitDecision`/`_block` + `_clientIp`/`_isAllowlisted` |
| Tigershield_Service_Blocklist | services/Blocklist.php | service | 7 | fs | **P1** | ⚠️ | IP-in-CIDR lookup; `_inCidrBin` binary CIDR match + atomic `replace()` |
| Tigershield_Service_Challenge | services/Challenge.php | service | 7 | crypto | **P1** | ⚠️ | captcha verify + HMAC-signed clearance cookie; `_sign`/`verifyClearance` forgery + window expiry |
| Tigershield_Service_RateLimit | services/RateLimit.php | service | 3 | fs | **P1** | ⚠️ | token-bucket; `hit()` limit trip + window rollover |
| Tigershield_Service_Waf | services/Waf.php | service | 2 | pure | **P1** | ⚠️ | signature/regex inspection → allow/deny; `_matchPattern`/`_matchNeedles` + body scan — **pure, high-value** |
| Tigershield_Model_Rule | models/Rule.php | model | 4 | fs | P2 | ⚠️ | WAF rule store + `compileCache()` that drives block decisions |
| Tigershield_Service_Crowdsec | services/Crowdsec.php | service | 6 | net | P2 | ⚠️ | pulls CrowdSec CAPI blocklist to cache; `_pullDecisions` parse + creds |
| Tigershield_Model_Event | models/Event.php | model | 4 | db | P3 | no | event log: record/topIps/countSince/datatable |
| Tigershield_Service_Rules | services/Rules.php | service | 4 | db | P3 | no | custom rule CRUD in transaction |
| Tigershield_Service_Events | services/Events.php | service | 1 | db | P3 | no | datatable feed |
| Tigershield_Service_Settings | services/Settings.php | service | 1 | db | P3 | no | persist config keys |
| Tigershield_Widget_Shield | widgets/Shield.php | helper | 5 | db | P3 | no | dashboard widget stats |
| Tigershield_AdminController | controllers/AdminController.php | controller | 4 | http | P3 | no | settings/events/rules screens |
| Tigershield_Bootstrap | Bootstrap.php | plugin | 0 | db | P4 | no | registers firewall plugin + widget |

**TigerDocs (WebTigers/TigerDocs) — 9 classes**

| class | path | kind | #pub | test | pri | sec | note |
|---|---|---|---|---|---|---|---|
| Docs_Model_Docs | models/Docs.php | model | 8 | fs | P2 | ⚠️ | scan/resolve/tree/search engine; `resolve()` path-traversal guard (`_safeSegment`) + visibility filter |
| Docs_IndexController | controllers/IndexController.php | controller | 3 | http | P2 | ⚠️ | public render by untrusted slug/locale; visibility gating + slug dispatch |
| Docs_Reference_Generator | bin/reference.php | service | 3 | fs | P2 | ⚠️ | token docblock/signature reference gen; `_parseFile`/`_parseSignature`/`_parseDoc` + `_slug` writes |
| Docs_Model_Index | models/Index.php | model | 3 | fs | P2 | no | scan-cache: fingerprint + atomic `var_export`/`include` (**no `unserialize`** → not P1); `_stillValid` invalidation |
| Docs_Service_Search | services/Search.php | service | 1 | fs | P3 | no | thin search passthrough |
| Docs_Service_Settings | services/Settings.php | service | 3 | fs | P3 | no | save/rebuild index + buildReference |
| Docs_AdminController | controllers/AdminController.php | controller | 3 | http | P3 | no | settings + help |
| Docs_Form_Settings | forms/Settings.php | form | 0 | pure | P4 | no | element defs |
| Docs_Bootstrap | Bootstrap.php | plugin | 0 | db | P4 | no | route override, sitemap, nav |

**TigerCodeSnippets (WebTigers/TigerCodeSnippets) — 6 pure functions (easiest wins in the whole manifest)**

| unit | path | kind | test | pri | note |
|---|---|---|---|---|---|
| `slug` | snippets/slug.php | helper | pure | P4 | string → url slug |
| `human_bytes` | snippets/human-bytes.php | helper | pure | P4 | bytes → human string |
| `time_ago` | snippets/time-ago.php | helper | pure | P4 | timestamp → relative |
| `ordinal` | snippets/ordinal.php | helper | pure | P4 | int → 1st/2nd/… |
| `initials` | snippets/initials.php | helper | pure | P4 | name → initials |
| `array_get` | snippets/array-get.php | helper | pure | P4 | dot-path accessor |

**TigerAPIDocs — 2 classes** (both thin): `Apidocs_IndexController` (http, P3, serves Swagger + spec),
`Apidocs_Bootstrap` (P4). **Tiger skeleton — 1**: `Bootstrap` (near-empty stub, P4). 

**⚠️ P1 security logic that is PROCEDURAL, not a class** (needs characterization tests now, and is a
refactor-to-testable candidate — you can't cleanly unit a 30-function script):

| file | repo | why P1 |
|---|---|---|
| `scripts/review.php` | TigerVendors | registry listing validator over **untrusted PR JSON + fetched GitHub content** — schema, public-repo, license, slug, tag-exists checks. The supply-chain gate. |
| `tiger-install.php` | tiger-install | web installer: `http_download`/`rcopy`/`resolve_release`/`do_install_files` — **downloads + unpacks a release tarball**, CSRF token, DB provision, owner creation over untrusted POST |
| `bin/tigershield.php` | TigerShield | CLI/cron CrowdSec entrypoint (refresh/status/provision/enroll) |
| `scripts/review-ai.php` / `scripts/compile-index.php` | TigerVendors | LLM review pass (fetches untrusted repo files) + CI index compiler |

**Section note:** TigerShield's 5 pure/fs decision engines (`Waf`, `Blocklist`, `Challenge`,
`RateLimit`, `Firewall`) are the highest-value non-tiger-core tests in the whole set — a WAF that
fails open silently is worse than none. `Tigershield_Service_Waf` is `pure` → start there.

### 6.6 JS component repos — TigerUpload, TigerLightbox  — 6 units

**Harness prerequisite (both repos): no JS test setup exists at all** — no `package.json` test
script, no `__tests__`/`*.test.js`, no jest/vitest/mocha. Step zero = pick a runner (**vitest +
jsdom** recommended — ESM-friendly, fast) and add XHR/FormData/`URL.createObjectURL` mocks.

| unit | path | kind | #pub | test | pri | sec | note |
|---|---|---|---|---|---|---|---|
| Uploader (TigerUpload) | tiger-upload.js | class | 12 | xhr | **P2** | ⚠️ | engine: queue/concurrency pump, per-file XHR progress, pub/sub `_emit`, `prepare(item,fd)` hook (sync+promise), retry/cancel/abort, `_reject` accept/maxSize validation. Riskiest: `_active` accounting on load/error/abort + async prepare send-after-`then` |
| TigerLightbox (viewer) | tiger-lightbox.js | module | 2 | dom | **P2** | ⚠️ | IIFE state machine: `open/close`, render img/video/iframe(pdf), `go()`/swipe modulo-index nav, preload, Esc/arrow + Tab focus-trap. Riskiest: index clamp/wraparound, focus-trap Tab cycle, `close()` teardown timing; media `src` = embed/XSS surface |
| TigerUpload.list (renderer) | tiger-upload-list.js | function | 1 | dom | P3 | ⚠️ | default renderer: subscribes → thumbnail+progress rows, remove/retry wiring. `esc()` guards name/error/ext but **`previewUrl` img src is unescaped** — XSS test |
| lightbox auto-wire | tiger-lightbox.js | module | 0 | event | P3 | ⚠️ | delegated click on `[data-tiger-lightbox]`; `guessType(src)` from untrusted href; builds `querySelector` from group value (escapes only `"`) — **selector-injection** test |
| tigerParse | tiger-upload.js | function | 1 | pure | P3 | no | `/api` envelope parser over fake `{status,responseText}`; status vs `result!==1` branching + firstMessage null-guard — **easy pure win** |
| TigerUpload (facade) | tiger-upload.js | object | 1 | pure | P4 | no | `create(opts)`→Uploader export glue |

---

## 7. Summary counts

**Everything in scope is untested** (except TigerZF, §2). Totals below are first-party units needing
tests written from zero.

| Area | Units | of which P1 (security-critical) |
|---|---|---|
| tiger-core — data layer (6.1) | 35 | 13 |
| tiger-core — kernel (6.2) | 59 (+1 vendored excluded) | 17 |
| tiger-core — media + location (6.3) | 18 | 7 |
| tiger-core — core/ + modules/ (6.4) | 67 | ~22 (services + auth/file controllers) |
| **tiger-core subtotal** | **179** | **~59** |
| TigerShield (6.5) | 14 | 5 |
| TigerDocs (6.5) | 9 | 0 (3 P2 with traversal/visibility surface) |
| TigerCodeSnippets (6.5) | 6 | 0 (all pure — trivial wins) |
| TigerAPIDocs + Tiger skeleton (6.5) | 3 | 0 |
| **satellite PHP subtotal** | **32** | **5** |
| JS — TigerUpload + TigerLightbox (6.6) | 6 | 0 (4 have XSS surface) |
| **TOTAL first-party units** | **~217** | **~64 P1** |

Plus **~5 procedural P1 files** (not classes): `TigerVendors/scripts/review.php`,
`tiger-install/tiger-install.php`, `TigerShield/bin/tigershield.php`, `review-ai.php`,
`compile-index.php` — characterization tests + refactor-to-testable candidates.
Plus **~31 tiger-core migrations** — one "migrate up→down on a clean DB" integration test, not per-file.

**By testability (drives harness build order):**
- **pure** (~25) — no harness; write first for fast baseline coverage. Includes all 6 TigerCodeSnippets
  functions, `Tigershield_Service_Waf`, `Tiger_Uuid`, `tigerParse`, the `Seo_Service_*` statics,
  `Tiger_Cms_Renderer`, most forms.
- **db** (~90) — the fixture-DB decision (§3) gates all of these. The bulk of the work.
- **fs** (~35) — temp-dir sandboxes; the install/extract/code-runtime P1s live here.
- **net** (~25) — mock/contract tests + optional cred-gated live smoke (AWS/GCS/Azure/GitHub/CrowdSec).
- **crypto** (~6) — test key/pepper; the highest-value-per-test cluster (auth/rotation).
- **http** (~30) — controllers + plugins; mostly dispatch/smoke.
- **static** (~8) — reset registries between cases.

## 8. Recommended authoring order (maps §5 spine → this inventory)

1. **Stand up the PHPUnit harness** (§3) — bootstrap + fixture MySQL + registry-reset + test secrets.
   *Nothing below runs without this.*
2. **Pure quick-wins** for a fast green baseline + to prove the harness: TigerCodeSnippets ×6,
   `Tigershield_Service_Waf`, `Tiger_Uuid`, `tigerParse`.
3. **P1 crypto/auth cluster:** `Tiger_Security`, `Tiger_Crypto`, `Tiger_Auth_Totp`,
   `Tiger_Model_UserCredential`, `Tiger_Service_Authentication`, `Tiger_Model_AuthChallenge`,
   `Tiger_Policy_Password`.
4. **P1 gateway/authz:** `Tiger_Ajax_ServiceFactory`, `Tiger_Acl_Acl`,
   `Tiger_Controller_Plugin_Authorization`, `Tiger_Model_OrgUser`, `Tiger_Model_Table` (tenant stamp +
   soft-delete — underpins every model).
5. **P1 install/update/RCE:** `Tiger_Module_Installer` (zip-slip), `Tiger_Vendor`, `Tiger_Update_Core`,
   `Tiger_Code_Runtime` + `Code_Service_Code`, `System_Service_Modules`, `Signup_Service_Signup`.
6. **P1 storage/media:** the shared storage-adapter traversal/visibility trait ×4 + `Media_FileController`.
7. **TigerShield P1 engines:** `Firewall`, `Blocklist`, `Challenge`, `RateLimit`.
8. **Procedural P1 characterization tests:** `review.php`, `tiger-install.php`.
9. **P2 substrate**, then the migration up/down integration test, then **P3 features**, then **P4 smoke**.
10. **CI:** wire the PHP 8.1–8.5 matrix (mirror TigerZF) + the vitest/jsdom JS suite; make green the
    1.0 `@api`-freeze gate.

---

## 9. Progress log (the running backfill tracker)

**2026-07-24 — harness promoted to `main` + CI (§3 DONE), then Wave 1.**

- **Foundation:** `phpunit.xml` + `tests/bootstrap.php` + `Support/{Unit,Integration}TestCase` + `composer
  test:*` + `.github/workflows/tests.yml` (unit 8.1–8.5 + integration on MariaDB), all green in CI.
- **Baseline unioned onto main (17 files):** crypto/auth/gateway (`Acl`, `Ajax_ServiceFactory`, `Auth_Totp`,
  `Crypto`, `Security`, `Uuid`, integration `OrgUser`+schema) + the commerce/license/agent/module set
  (`Crypto_Signature`, `License_{Authority,Checker}`, `Module_{Pricing,Compat,Dependency,Installer-sig}`, agent).
- **Wave 1 — 3 parallel no-DB clusters (+119 tests → 247 unit / 8 integration):**
  - ✅ **Media** — `Storage_Filesystem` (traversal guard + public/private split), `Storage` factory,
    `Image` (never-upscale), `Storage_S3` (`_fullKey` guard). *(§6.3)*
  - ✅ **CMS/routing/i18n** — `Cms_Renderer` (trust-tier dispatch), `Routing_Overrides` (reserved-prefix),
    `I18n_Country`, `Location_Place`. *(§6.2/6.3)*
  - ✅ **Module install** — `Module_Installer` (zip-slip/tar-slip guard + slug/manifest + migrationPaths),
    `Module_Discovery`, `Vendor` (sha256 + semver). *(§6.2)*

### Findings surfaced by the tests (source unchanged — decide + address separately)
1. **`Tiger_Cms_Renderer` markdown is NOT safe-mode** (Renderer.php ~83): `Parsedown::instance()->text()`
   with no `setSafeMode(true)` → raw HTML / `<script>` passes through, contradicting the "safe Markdown"
   labeling in the docblock + FEATURES/ARCHITECTURE. CMS bodies are admin-gated, so it may be an accepted
   trust tier — but the "safe" wording is misleading for any lower-trust path. Current behavior is pinned by
   `RendererTest::markdown_passes_raw_html_through_unescaped` so hardening is a deliberate, visible change.
2. **`Tiger_Media_Image` calls no-op `imagedestroy()`** (Image.php:92,94) — a PHP 8.5 deprecation (2 of the
   suite's 5). Trivial cleanup (remove the two calls).
3. **`Tiger_Module_Installer::_extract` has no explicit in-code traversal guard** — relies (safely, verified)
   on `ZipArchive`/`PharData` flattening `../`; the shell fallbacks (`unzip`/`tar`) also refuse traversal.
   No bug today; `InstallerExtractTest` pins the escape-proof invariant so a future extractor swap fails loud.

**2026-07-24 — Wave 2 (integration/DB model spine, +102 tests → ~136 unit / ~110 integration).** 4 parallel
agents, each on an isolated DB, collected + verified together (111 integration tests green on one DB):
- ✅ **Data-layer base** — `Model_Table` (v7/v4 mint, actor+org stamp immutability, soft-delete +
  `activeSelect`/`findById` exclusion), `Config`/`Option` scope isolation, `Db_Migrator`
  (ordering/idempotency/apply-after-success/rollback). *(§6.1)*
- ✅ **Auth models** — `AuthChallenge`, `UserCredential` (pepper-migration, TOTP-at-rest, lockout, recovery),
  `User` (verified-factor identity), `PasswordHistory`, `Policy_Password`. *(§6.1)*
- ✅ **Content/CMS** — `Page` (org cascade + publish gate), `Media` (private-URL scoping), `Menu`, `Org`,
  `Page/CodeVersion`. *(§6.1)*
- ✅ **ACL/module/session** — `Acl{Resource,Role,Rule}` (deleted-rule exclusion), `Module` (`inactiveSlugs`),
  `Login`, `Session` (`gc`), `Translation`. *(§6.1)*

**FIXED (a Wave-2 test surfaced it):** `Tiger_Model_PasswordHistory::recentForUser()` ignored its `$limit`
(a Select passed to `fetchAll()` drops ZF1's `$count` arg → returned ALL retained rows; stricter-than-
configured, not a hole). Limit moved onto the Select; regression test pins it.

**Findings (tracked, unchanged):**
4. **ACL loaders filter `deleted=0` only, not `status`** (`Model/Table.php` `activeSelect()`): a
   `status='inactive'` acl_rule still loads + affects decisions. Fine if soft-delete is the only intended
   "off", latent if `status` is ever expected to disable.
5. **`AuthChallenge::redeem()` single-use is a non-atomic TOCTOU** — check-then-consume + a read-modify-write
   `attempts` counter; safe single-threaded, wants a conditional `UPDATE … WHERE consumed_at IS NULL` (+
   affected-rows) under concurrent load. (Same shape worth auditing in other redeem/counter paths.)
6. v7 UUIDs collide within a millisecond (first 12 hex = ms) — the `substr(v7,0,12)` id idiom in tests is
   latently flaky; use `bin2hex(random_bytes())` for unique fixture values.

### Wave 3 — the `/api` service + auth-service spine (LANDED 2026-07-24)
**Result:** 4 agents → **135 new tests** collected + verified together on one DB → the combined integration
suite is **250 tests / 845 assertions green** (was 116). Files: `Signup/SignupServiceTest` (19),
`Code/{RuntimeTest,CodeServiceTest}` + `System/{ModulesServiceTest,UpdatesServiceTest}` + `License/CheckerTest`
(35), `Access/{UserServiceTest,OrgServiceTest}` + `Cms/{PageServiceTest,MenuServiceTest,SettingsServiceTest}`
(55), `Service/AuthenticationTest` (26). Auth used Zend's `Zend_Session::$_unitTestEnabled` array-backed mode
to exercise the REAL session/lock/2FA/return-to paths under CLI (not stubs).

**Bug fixed at collection (a test surfaced it):** `Signup_Service_Signup::create()` committed the tenant graph
in `_transaction()` and only THEN issued the `email_verify` challenge + sent mail — with the challenge
`issue()` *outside* the mail try/catch. A throw there unwound into `create()`'s catch → `result=0` **on a
fully-committed account** (unusable, and un-recreatable behind email-uniqueness). Fix: issue the challenge
INSIDE the transaction (atomic with the user — a challenge-write failure now rolls the whole signup back);
`_sendVerification` reduced to best-effort post-commit mail. (Same class of "notification failure fails a
committed write" worth auditing elsewhere.)

**Findings (tracked, not fixed — characterized green):**
7. **Harness: base per-test `beginTransaction()` can't nest a service's own `_transaction()`** (ZF1's PDO
   adapter doesn't ref-count; MySQL throws "already an active transaction"). Hit independently by 3 agents;
   they worked around it (commit-and-purge / escape-and-scrub / drive the layer beneath). **Follow-up: add a
   savepoint-aware/reentrant isolation mode to `IntegrationTestCase`** so service happy-paths test with clean
   rollback isolation — unblocks every future service wave.
8. **`Cms_Service_Settings::save`** writes two `config` rows without a `_transaction()` — partial state
   possible if the 2nd throws; diverges from the documented validate→transaction flow. (Same shape, benign
   single-statement, in `Access_Service_Org::save` / `Cms_Service_Menu::save`.)
9. **`Tiger_Code_Runtime` writes real files** to `storage/cache/code` + `public/_code`; confirm both are
   gitignored so a test/compile run can't leave artifacts staged.
10. DataTables `status`/`type` toolbar filters scope **both** `recordsTotal` and `recordsFiltered` (recordsTotal
    is the filtered working set, not the grand total) — intentional per the model docblocks; noted for consumers.

### Wave 3 — the `/api` service + auth-service spine (agents' brief, 2026-07-24)
**Base scaffolding landed** on `test/int-base`: `IntegrationTestCase` now ships `login()`/`loginAs()`/
`logout()` (a real non-persistent `Zend_Auth` identity + the REAL shipped `Tiger_Acl_Acl` policy, so a
service's `_isAdmin()`/ACL gate decides against the rules that actually ship, not a fixture) — proven by
`ServiceScaffoldTest` (5 tests) dispatching the real admin-gated `Access_Service_User`. And `tests/bootstrap.php`
gained a **module-class autoloader** (`Mod_Type_Name` → `modules/<mod>/<types>/Name.php`, `Mod_XController` →
`controllers/`) registered LAST — so a real `/api` service + its form/model instantiate with no `require_once`
and no ZF1 module-resource-loader boot. This is the gate that unblocks the service wave.

Then **4 parallel agents** (own worktree + own DB `tiger_test_w3a-d`, off `test/int-base`):
- **A / signup** — `Signup_Service_Signup` (guest mass-create: happy path → user+org+membership, validation +
  rollback, guest-allowed ACL).
- **B / RCE cluster** — `Tiger_Code_Runtime` (compile-gate/platform-scope), `Code_Service_Code`,
  `System_Service_Modules`, `System_Service_Updates` (superadmin deny-by-default + nag-never-disable).
- **C / admin CRUD** — `Access_Service_User`/`Org`, `Cms_Service_Page`/`Menu`/`Settings` (ACL gate, datatable
  envelope, validate→transaction, soft-delete/restore).
- **D / auth engine** — `Tiger_Service_Authentication` (password login, lockout, pepper, one-time challenges,
  2FA orchestration). Collect → one DB → fold into ONE PR (dodges stacked-squash pain, per Waves 1+2).

### Wave 3 tail + test-infra (LANDED 2026-07-24) → 265 integration green
- **Finding #7 RESOLVED — re-entrant transaction isolation.** `tests/Support/SavepointAdapter.php` (a
  test-only `Zend_Db_Adapter_Pdo_Mysql` subclass) maps nested `begin/commit/rollBack` onto MySQL
  SAVEPOINTs, so a service's own `_transaction()` (or a model `save()`) composes inside the per-test
  outer transaction instead of throwing — and the outer rollback still discards everything. Wired into
  `IntegrationTestCase::adapter()`. `SavepointIsolationTest` (6) locks it, incl. a real
  `Access_Service_User::save` nesting with no commit-and-purge. **Future service tests no longer need the
  escape-and-scrub workaround** — dispatch inside the base txn and rely on rollback isolation.
- **`Tiger_Controller_Plugin_Authorization`** (9) — the live-role guarantee: `_resolveRole()` reads the
  role FRESH from `org_user` (session role is ignored; a revoked membership drops to base next request; a
  locked session → guest; actor/org stamped) + `_resourceFor()` controller→resource mapping. The
  preDispatch→redirect/403 cycle (front controller + exiting redirector) stays a functional/smoke concern.
- **Coverage in CI.** `.github/workflows/tests.yml` gained a `coverage` job (pcov, full suite) that
  publishes a line-% summary to the PR and enforces a ratcheting `MIN_COVERAGE` floor. **Baseline ≈20%
  lines** (2863/14397) — the tested spine is ~100%, the drag is untested feature modules (blog/seo/media/
  profile/analytics/backup/identity/schedule at 0%) + the marketplace/install cluster. **Target: 90%.**

### Wave 4 — module breadth + install cluster (LANDED 2026-07-24) → coverage 20.5% → 37.9%
6 parallel agents (own worktree + DB each), **+316 integration tests** (265 → 581 green) + install-cluster unit
tests. Per-bucket line-%: **ally 100 · seo 88 · blog 83 · signup 87 · search 73 · access 69 · schedule 62 ·
profile 56 · identity 45 · analytics 41 · media 34 · backup 31 · system 37**; the kernel install/marketplace
cluster ~2–8×'d (`Dependency` 97 · `License_Authority` 94 · `Vendor` 80 · `Update_Checker` 79 · `Installer` 68 ·
`Github` 66) lifting **library/Tiger 25 → 32%**. No product bugs found. CI floor `MIN_COVERAGE` 18 → 35.
- **Real fix at collection:** `Tiger_Db_Migrator` gained an optional **ledger-table** arg (default
  `tiger_migration`, prod unchanged); `MigratorTest` now uses an ISOLATED ledger. Root cause: `rollback()`
  reverses the newest applied version GLOBALLY, and a module's timestamp-versioned migration (committed past
  the per-test rollback — DDL auto-commits — by the install lifecycle test) sorted above the `9xxx` fixtures →
  a cross-test flake. Hermetic ledger kills it.
- **Recurring ceiling (not bugs):** `is_uploaded_file()` gates upload happy-paths (profile Avatar/OrgLogo,
  media upload) → unreachable from CLI; render-only controllers + `exit;`-ending file/serve actions need a
  full MVC/view boot the harness skips. These are functional/HTTP-test concerns — the % gaps below ~90 on
  media/profile/backup are mostly these, not missing logic.

### Wave 5 — the library/Tiger KERNEL (LANDED 2026-07-24) → coverage 37.9% → 59.2%
7 parallel agents by sub-package, **+~584 tests (861 → 1445 combined green)**, kernel **library/Tiger 32% → 66%**.
Per-subpackage lines: Model 51→97 · Service 57→89 · Ajax 70→89 · Controller 16→77 · Admin 0→81 · Session 0→96 ·
View 0→98 · OpenApi 0→94 · Generator 0→100 · Policy 85→100 · Acl 85→89 · Location 7→84 · I18n→95 · Validate 21→74 ·
Media(lib) 25→~85 · Backup 0→~90 · Code(lib) 44→~85 · Agent(lib) 3→~72 · Google 0→29 · Application 0→60. **No behavioral
bugs.** One forward-compat source fix: `Tiger_Model_AgentMessage::append()` implicit-nullable `array $meta=null` → `?array`
(PHP 8.5 deprecation, error in PHP 9). **Remaining kernel gap (~3,150 lines) is the genuinely-hard I/O:** the live-boot
`Tiger_Application_Bootstrap::_init*` orchestration, `Update_Composer`/`Update_Core` (composer proc_open + `vendor/` swap),
provider/authority/GA/reCAPTCHA live HTTP, ClamAV/Rekognition scanners, AWS-SDK S3 I/O — all functional/live territory.
- **Cross-test isolation footguns** the agents surfaced (each guarded locally; recorded for future waves): `Tiger_Model_Org::$_siteOrgId`
  process-static must be reset to EXACTLY `null` (restoring `''` poisons lazy site-org resolution); `Zend_Translate`
  set to null in the registry stays registered-as-null → use `offsetUnset`, not `set(null)`; `Tiger_Application::defineConstants()`
  mints `MODULES_PATH` etc. → the app-boot test runs `#[RunInSeparateProcess]`. **RECOMMENDED base hygiene (deferred):** reset
  `Org::$_siteOrgId` in `IntegrationTestCase::tearDown`. CI floor `MIN_COVERAGE` 35 → 55.

### Wave 6 — remaining modules + the dispatch harness (LANDED 2026-07-24) → coverage 59.2% → 70.0%
First landed a **controller dispatch harness** (`tests/Support/ControllerTestCase.php`, PR #66): instantiates a
controller + dispatches ONE action with view-rendering OFF, so the action BODY runs (branch logic, `_json` body,
redirect/`_forward`) without the theme/view-script stack — unlocking `core/controllers` (0% before). Then 5 agents:
core-controllers 0→**87%** (all 6: Api 80, Auth 79, Error 95, Index 87, Page 98, Admin 91), cms 33→**94**, code 41→**90**,
agent module 6→**66-78**, system 37→**59**, + a module-controller sweep (profile 68, access 84, media/analytics/identity/
blog/signup/search/schedule controllers 90-100%). ~167 tests; combined suite **1617 green**.
- **REAL BUG FIXED (a test found it):** `Backup_IndexController::_json(int,string,array):string` was an INCOMPATIBLE
  override of `Tiger_Controller_Action::_json($data,$status)` → a PHP 8.5 **fatal at class load**, so EVERY `/backup`
  request 500'd (the backup admin UI was entirely broken). Renamed the helper to `_jsonBody()`; replaced the
  characterization test with real dispatch coverage.
- **Harness hardening (3 agents hit it):** folded `redirector->setExit(false)` into `ControllerTestCase` setUp (the
  redirector `exit`s after headers by default → would kill the PHPUnit process on a controller redirect).
- CI floor 55 → 66. Ceilings (bounded honestly, not chased): `Updates::_applyOne` would run a real `composer update`/
  `vendor/` swap on a dev box (covered at guard level only); `is_uploaded_file()` uploads; live-model agent turns;
  render-only `.phtml` leaves; `pcov` under-reports module `elements()` forms (multi-line-array-literal artifact).

### Next waves (priority order) — the drive to 90%
### Next waves (priority order) — the drive to 90%
- **Wave 6 — the remaining MODULES:** `agent` module (462 @ 5% — needs a provider-adapter stub, but the `Tiger_Agent_*`
  library it builds on is now ~72% so the seams exist), `cms` module (510 @ 33%), `system` remainder (579 @ 37%), `code`
  module (238 @ 41%), profile/media/analytics/backup/identity remainders (mostly upload-ceiling + render-only), and
  `core/controllers` (360 @ 0% — needs a controller-dispatch harness). ~2,700 reachable module lines.
- **Wave 7 — the hard kernel remainder:** an integration harness that boots enough of `Tiger_Application` to cover the
  `_init*` cascade; live-I/O seams for Update/Github behind a real local fixture server if worth it.
- **Wave 8 — satellite repos:** stand up a harness in each, then TigerShield WAF engines first.

---
*Manifest generated 2026-07-18 by 6 parallel read-only scans; graduated into `tests/COVERAGE-PLAN.md` +
harness on main 2026-07-24. This is the living tracker — update the §9 log as waves land.*
