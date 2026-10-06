# NADI — FINAL VERIFICATION REPORT

**Audit date:** 2 October 2026  
**Repository:** `NADI-LSP-MIGAS-FINAL` (uploaded source)  
**Truthful state:** **SOURCE RELEASE CANDIDATE — FINAL PRODUCTION PASS NOT YET DECLARED**

## 1. Forensic baseline

| Item | Observed |
|---|---|
| Laravel | 13.31.0 |
| PHP requirement | `^8.4.1` |
| Frontend | React 19 + Vite 8 |
| Production DB target | MySQL 8.x |
| Migrations | 33 |
| Tables created by migrations | 41 |
| Models | 34 |
| Routes | 83 total = 79 API + 4 web |
| Forced-password middleware routes | 73 |
| Test methods discovered | 119 |
| PHP files linted | 155 |
| PHP syntax failures | 0 |

## 2. Source/package audit

- Uploaded ZIP archive integrity: PASS (`unzip -t`).
- Bundled Composer vendor integrity: 114 locked packages matched 114 installed packages; 0 missing, 0 extra, 0 version mismatches.
- Original package checksum manifest: PASS before repository corrections; preserved as `ORIGINAL_PACKAGE_MANIFEST.sha256`.
- `.env`: absent.
- Database dumps / SQLite runtime DB: none found in package root audit.
- Runtime logs / session payloads: none found; only `.gitignore` placeholders were present.
- `__MACOSX`: none.
- `.DS_Store`: none.
- High-confidence secret heuristic scan outside dependency trees: no private keys, AWS access keys, GitHub tokens, OpenAI-style keys, or suspicious hard-coded generic secrets detected.
- Frontend demo credential markers: none found in `resources/js`, `resources/views`, or `public`.
- Demo accounts remain limited to server-side prototype seeding; `NADI_DEMO_PASSWORD` is explicitly required by `PrototypeSeeder`.
- The uploaded ZIP bundled `node_modules`, but npm `.bin` symlink semantics were not preserved: `node_modules/.bin/vite` was extracted as a regular file. A direct `npm run build` therefore failed with an import path error before it could be treated as build evidence. The bundle also contains macOS arm64 native frontend bindings, so it is not portable production evidence.
- `FINALIZE_AND_RUN_MAC.command` was corrected to perform `composer install` and a clean `rm -rf node_modules && npm ci` from lockfiles before build, then require the Vite manifest, run migrations/tests, and fail if readiness does not pass.

## 3. Static/runtime gates actually executed

| Gate | Result | Evidence |
|---|---|---|
| Laravel boot | PASS | `php artisan --version` → Laravel 13.31.0 |
| Route inventory | PASS | 83 total; 79 API; 4 web |
| HTTP liveness `/up` | PASS | local runtime smoke returned HTTP 200 |
| Readiness `/api/health/ready` | EXPECTED FAIL | HTTP 503; response reported missing PHP extensions, build, and DB without exposing DB exception details |
| Application root `/` | BLOCKED | HTTP 500 because production frontend build is absent |
| PHP syntax | PASS | 148 files, 0 syntax errors |
| Source secret scan | PASS | no high-confidence secret markers |
| Package guard | PASS | packager refused because `public/build/manifest.json` is absent |
| PHPUnit | BLOCKED | PHP CLI lacks `dom`, `mbstring`, `xmlwriter` |
| Pint | BLOCKED | missing `mbstring` and `xml` |
| Route cache smoke | BLOCKED | missing `DOMDocument` (`dom`) |
| View cache smoke | BLOCKED | missing `DOMDocument` (`dom`) |
| Clean `npm ci` | BLOCKED | registry fetch failed with `EAI_AGAIN`; npm cache incomplete |
| Bundled `node_modules` build attempt | FAIL / INVALID EVIDENCE | `.bin/vite` was not preserved as an npm symlink after ZIP extraction; bundled native packages are macOS arm64 only |
| Vite production build | NOT EXECUTED | clean dependency install did not complete |
| MySQL `migrate:fresh` | BLOCKED | MySQL runtime absent; PHP `pdo_mysql` absent |
| Full production release-check | BLOCKED | required PHP extensions + MySQL + frontend build unavailable |
| Browser application smoke | BLOCKED | production frontend build unavailable |
| Deterministic final package | CORRECTLY BLOCKED | `scripts/package_release.py` refuses without Vite manifest |

## 4. Production configuration isolation check

`php artisan nadi:release-check --production --skip-db --skip-build --json` was executed with production-like environment values to isolate source/configuration checks.

Production configuration checks passed for:

- PHP version >= 8.4.1;
- `APP_ENV=production`;
- `APP_DEBUG=false`;
- HTTPS URL;
- MySQL selected;
- database session driver;
- encrypted session;
- secure cookie;
- demo mode disabled;
- demo seeding disabled;
- application key present;
- writable runtime directories.

The isolated check failed only for unavailable runtime extensions in the current execution environment: `dom`, `mbstring`, `xml`, and `pdo_mysql`.

## 5. Documentation discrepancy corrected

The `FINAL_RELEASE_NOTES.md` embedded in the uploaded ZIP was an older short document that referred to the package as a final deliverable and stated Iterations 1–16 were finalized. The separately uploaded, newer `FINAL_RELEASE_NOTES.md` correctly states that Iterations 14–16 are not verified and final runtime validation is pending. The repository copy has been replaced with that newer truthful release note.

## 6. Release decision

### SOURCE IMPLEMENTATION
**COMPLETE for the current declared scope**, based on static/source inspection and existing test inventory.

### SOURCE RELEASE CANDIDATE
**READY / AUDITED**.

### FINAL PRODUCTION PASS
**NOT DECLARED.** The mandatory runtime gates cannot truthfully be marked PASS in this execution environment.

A final production ZIP must not be generated until all of the following execute successfully on a suitable runner:

1. clean Composer install from lockfile;
2. clean `npm ci`;
3. `npm run build` producing `public/build/manifest.json`;
4. clean MySQL 8.x migration validation;
5. full `php artisan test`;
6. `vendor/bin/pint --test`;
7. `php artisan nadi:release-check --production`;
8. HTTP readiness and browser/runtime smoke;
9. deterministic `scripts/package_release.py` packaging;
10. archive integrity + final SHA-256 verification.

No result in this report should be interpreted as a substitute for a gate that was blocked or not executed.
## 7. Audited source checkpoint

Because the mandatory runtime gates remain blocked, no artifact is labeled `FINAL`. A clean checkpoint named `NADI-LSP-MIGAS-AUDITED-SOURCE-RC-20261001.zip` is produced separately for continuation on a suitable runner. It excludes dependency trees, secrets, runtime databases/logs/caches, and stale frontend build output, and includes an `AUDITED_SOURCE_RC_MANIFEST.sha256` for source-file integrity verification.

A final local check also confirmed that the missing PHP extensions are not present as disabled local `.so` modules and no suitable PHP/MySQL packages are available in the local apt cache; network/package-registry access is therefore genuinely required to close those environment gates here.



## 8. Iteration 14.8 verification update — 2 October 2026

The current repository has undergone another cross-module historical-consistency and deployment-hardening pass. The current executable/static evidence is:

| Check | Current result |
|---|---|
| PHP source lint | PASS — 150 files / 0 syntax errors |
| Migration inventory | 29 files / 41 table creates / 0 duplicate creates |
| Model inventory | 34 |
| Test methods discovered | 101 — discovery only, not runtime execution |
| Frontend ↔ backend contract | PASS — 79 Laravel API routes / 78 frontend contracts / 0 missing |
| Fixed historical demo password scan | PASS — 0 source hits |
| PHP manifest/lock contract | PASS — `^8.4.1`, Composer content-hash matches current `composer.json` |
| Controlled packager reproducibility | PASS — byte-identical archives from identical source/epoch |

Historical evidence fixes now cover Certification, Finance, IT, Governance, Risk/Action, and Management Review output. Deployment scripts also fail before maintenance mode when the runtime/build/config preflight is invalid, and production readiness rejects template DB credentials/URL placeholders.

The actual environment is still missing Composer CLI, PHP `mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`, and a MySQL 8 runtime. Therefore no statement in this report upgrades PHPUnit, real MySQL migrations, Pint, real Vite build, production release-check, or browser E2E to PASS.

## 9. Iteration 14.9 verification update — 2 October 2026

Additional source hardening verified in this iteration:

- deterministic Evidence Pack ZIP writer with path/duplicate-entry protection;
- per-payload-file SHA-256 and byte counts in Evidence Pack `manifest.json`;
- model-level immutable guards for FinancialRecord and CertificateIssuance plus existing ReportSnapshot immutability;
- MySQL update/delete triggers for `financial_records`, `certificate_issuances`, and `report_snapshots`;
- certification and IT integration publishing re-validates operational lifecycle/chronology after target-row locking;
- exact production database engine assertion requires MySQL 8.x and explicitly rejects MariaDB.

Executed static/release-engine evidence:

```text
PHP files linted               152
PHP syntax errors              0
Migrations                     30
Schema::create declarations    41
Models                         34
Test methods discovered        108
Laravel API routes parsed      79
Frontend API contracts parsed  78
Missing frontend contracts     0
Historical demo password hits  0
Configuration/script parse     PASS
```

Controlled release-engine packaging with an explicit synthetic Vite manifest produced two byte-identical archives:

the same SHA-256 digest for both controlled archives

Archive integrity passed and no runtime `.env`, `vendor`, `node_modules`, Python cache, runtime database, or log entries were present. This is packager verification only and not a real Vite build.

Runtime preflight still fails because the runner lacks Composer CLI and PHP `mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`; MySQL 8 is unavailable. A clean `npm ci` attempt did not complete in the available runner/network window and its partial `node_modules` directory was removed. Accordingly, clean install, MySQL migration, PHPUnit, Pint, real Vite build, production release-check, and browser E2E remain pending and `FINAL PASS` is not declared.

## 10. Iteration 14.10 verification update — 2 October 2026

Source hardening verified in this iteration:

- historical dashboard/executive ActionItem counts now use creation/completion state as-of `report_as_of` and historical overdue checks use the report cutoff rather than today's date;
- historical overview `last_updated_at` no longer exposes source timestamps after the report cutoff;
- certificate issuance references receive API + database uniqueness protection;
- batches with certificate issuance evidence are parent-delete protected in Eloquent and, on MySQL, by a parent trigger so cascading deletion cannot silently remove immutable issuance evidence;
- production deployment failure after entering maintenance mode is fail-closed and no longer automatically brings the application online after a failed migration/cache/readiness step.

Verification evidence after these changes:

```text
PHP source files                156
PHP syntax errors               0
Migrations                      31
Schema::create declarations     41
Unique created tables           41
Models                          34
Test methods discovered         110
Laravel API routes              79
Frontend API contracts          78
Missing frontend contracts      0
Fixed historical demo password  0 hits
Shell/Python/JSON/XML/YAML       PASS
```

Composer freshness was verified using PHP `json_encode(..., 0)` and the same relevant-key ordering used by Composer Locker:

```text
computed content-hash = dbf1fce00e5f0b540d001cb016176a70
composer.lock hash    = dbf1fce00e5f0b540d001cb016176a70
fresh                 = YES
```

The locked package set was also compared with the previously available vendor cache and all 114 package name/version pairs match. That cache was used only for a Laravel boot/route inventory smoke (`Laravel 13.31.0`, 83 routes / 79 API) and is **not** accepted as clean Composer-install or PHPUnit evidence.

A new clean `npm ci` attempt again failed to complete within the available runner/network execution window. The resulting partial `node_modules` directory was removed. Consequently real Vite build remains pending.

Mandatory gates still not executed successfully on this runner: clean Composer install, MySQL 8 `migrate:fresh`, full PHPUnit on MySQL, Pint, clean npm/Vite production build, production release-check with live database/build, and browser E2E. `FINAL PASS` remains undeclared.

Controlled Iteration 14.10 release-engine smoke after the final source changes produced two byte-identical archives:

```text
SHA-256 A = c7ee9861f7380be3c9f1dd8e1d30ade102f66ac7ed940b2c2e720dc445844eaa
SHA-256 B = c7ee9861f7380be3c9f1dd8e1d30ade102f66ac7ed940b2c2e720dc445844eaa
byte-identical = PASS
unzip integrity = PASS
RELEASE_MANIFEST.json = PRESENT
forbidden runtime entries = 0
```

The package intentionally retains `.env.example` and `.env.production.example` as non-secret deployment templates. The smoke uses a synthetic Vite manifest/asset and therefore remains release-packager evidence only, not a substitute for `npm run build`.

## 11. Iteration 14.11 verification update — 2 October 2026

Release-critical source changes verified in this iteration:

- `/api/decisions` is now read-only and does not synchronize/mutate RiskSignal state merely because the page was opened;
- Management Review creation performs permission/scope revalidation on locked actor/signal rows within the commit transaction;
- manual KPI CSV import has SHA-based identical-file idempotency, deterministic KPI parent locking, and per-measurement before/after audit evidence;
- historical Certification analytics reconstructs post-cutoff result/lifecycle corrections from audit history and expands historical candidates when later corrections move decision/due dates outside the requested period;
- integration Certification/IT mutations now provide audit before/after state needed by historical reconstruction;
- certification batch creation locks referenced Scheme/TUK/Assessor masters and rechecks active/archive state, closing the archive-vs-create TOCTOU window;
- release verification and CI deterministic-package gates were strengthened to prevent SQLite/database/packaging false positives.

Clean-source verification after the above changes:

```text
PHP source files                 156
PHP syntax errors                0
Migrations                       31
Schema::create declarations      41
Unique created tables            41
Models                           34
Test methods discovered          114 (discovery only)
Laravel API routes (static)      79
Frontend API contracts           78
Missing frontend contracts       0
Historical fixed demo password   0 hits
JSON/XML/YAML                     PASS
Shell/Python syntax               PASS
```

Composer freshness was recalculated using the same relevant-key ordering and default JSON encoding behavior as Composer Locker:

```text
computed content-hash = dbf1fce00e5f0b540d001cb016176a70
composer.lock hash    = dbf1fce00e5f0b540d001cb016176a70
fresh                 = YES
```

Current real runtime preflight:

```text
PHP 8.4.23       PASS
Node 22.16.0     PASS
npm 10.9.2       PASS
Composer CLI     MISSING
PHP mbstring     MISSING
PHP dom          MISSING
PHP xml          MISSING
PHP xmlwriter    MISSING
PHP pdo_mysql    MISSING
MySQL 8 runtime  UNAVAILABLE
```

Accordingly, no claim is made that Composer clean install, MySQL migration, PHPUnit, Pint, real Vite build, production release-check, or browser E2E has passed. `FINAL PASS` remains undeclared.

Controlled release-engine verification after the final Iteration 14.11 source/document changes:

```text
SHA-256 A = d8bca871ad137b2c626016dce8d566f2498c60bc019b2676a53d706bf94261b3
SHA-256 B = d8bca871ad137b2c626016dce8d566f2498c60bc019b2676a53d706bf94261b3
byte-identical = PASS
unzip integrity = PASS
RELEASE_MANIFEST.json = PRESENT
forbidden runtime/dependency/cache entries = 0
```

The controlled smoke uses a synthetic Vite manifest and asset. It validates only release-packager determinism/hygiene and is not accepted as `npm run build` evidence.

## 12. Iteration 14.12 verification update — 2 October 2026

Release-critical source changes verified in this iteration:

- append-only model/MySQL protection for security audit and authentication-event evidence;
- immutable identity and no-hard-delete protection for DataImportBatch provenance;
- parent-delete guards for provenance/governance/certification evidence;
- no-hard-delete lifecycle protection for users, RiskSignals, ActionItems, Management Reviews, and their items;
- transactional actor permission revalidation for report generation, master-data mutation, custom KPI measurements, and initial legacy KPI CSV provenance creation;
- domain-scoped report source manifests to prevent cross-domain source filename/SHA/import metadata disclosure;
- inactive selected DataSources are rejected while creating manual KPI CSV provenance;
- import terminal audit attribution uses the immutable batch creator rather than mutable current-session state.

Clean-source verification after the final source changes:

```text
PHP source files                 155
PHP syntax errors                0
Migration files                  33
Schema::create declarations      41
Unique created tables            41
Duplicate table creates          0
Models                           34
Test methods discovered          119 (discovery only)
Laravel API routes parsed        79
Frontend API contracts parsed    78
Missing frontend contracts       0
Historical fixed demo password   0 hits
JSON/XML/YAML                     PASS
Shell/Python syntax               PASS
Composer manifest/lock            FRESH
```

Runtime prerequisite investigation confirms that the runner has PHP 8.4.23, Node 22.16.0, npm 10.9.2, GCC/make and several runtime shared libraries, but no Composer CLI, `phpize`, `php-config`, PHP development headers, PHP `mbstring/dom/xml/xmlwriter/pdo_mysql`, or MySQL 8 server. The missing extensions therefore cannot be built locally from the available runner contents. A new clean `npm ci` attempt again did not finish within the runner/network window; its partial `node_modules` tree was removed.

Consequently clean Composer installation, MySQL 8 migration, full PHPUnit, Pint, real Vite production build, production release-check with a live database/build, and browser E2E remain pending. Iteration 14.12 is the **source-level closure point**; the remaining mandatory work is executable runtime validation and remediation of any failures found there. `FINAL PASS` remains undeclared and no final release ZIP is produced.

Controlled release-packager verification for the final Iteration 14.12 source/document state:

```text
SHA-256 A = 8e5d84ab8c58c1a353be3a19988daa8af70a234979c6ae48416019a08e11b2a0
SHA-256 B = 8e5d84ab8c58c1a353be3a19988daa8af70a234979c6ae48416019a08e11b2a0
byte-identical = PASS
unzip integrity = PASS
RELEASE_MANIFEST.json = PRESENT
forbidden runtime/dependency/cache entries = 0
```

The test intentionally used a synthetic Vite manifest/asset to validate package determinism only. That fixture and both smoke archives were removed before the clean-source checkpoint. It does not promote the real frontend-build gate to PASS.

## 13. Iteration 15.1 verification update — 2 October 2026

Iteration 15 runtime closure began from the clean 14.12 source checkpoint.

Verified changes/results:

- historical vendor cache exactly matches all 114 locked Composer package name/version pairs; diagnostic use only;
- Laravel cache-assisted boot: 13.31.0, 83 routes / 79 API;
- PHPUnit extension bypass diagnostic still fails immediately on missing `DOMDocument`, confirming `dom` is an actual executable prerequisite and not merely a wrapper check;
- `scripts/verify-release.sh` now performs guarded clean MySQL `migrate:fresh` before tests;
- production APP_KEY strength/length behavior verified directly through `ReleaseReadinessService`: random 32-byte key PASS, all-zero 32-byte key FAIL;
- database-backed cache/queue readiness behavior verified directly: database/database PASS, array/sync FAIL;
- production deployment uses runtime-only preflight (no Node/npm requirement) and verifies packaged file hashes before dependency/database operations;
- controlled extracted release passed manifest verification; tampering `README.md` caused verifier failure as designed;
- packaging with a Vite manifest that references a missing import entry was rejected;
- frontend/API contract remains PASS with 79 Laravel API routes, 78 frontend contracts, 0 missing.

Current local runtime blockers remain:

```text
Composer CLI      MISSING
PHP mbstring      MISSING
PHP dom           MISSING
PHP xml           MISSING
PHP xmlwriter     MISSING
PHP pdo_mysql     MISSING
MySQL 8 runtime   UNAVAILABLE
npm registry      EAI_AGAIN
```

A new clean `npm ci` attempt again reached registry fetches and failed on DNS; the partial dependency tree was removed. No claim is made that clean Composer install, real Vite build, MySQL migration, PHPUnit, Pint, production release-check, or browser E2E passed.

Final clean-source/static inventory after Iteration 15.1 changes:

```text
PHP source files                 156
PHP syntax errors                0
Migration files                  33
Schema::create declarations      41
Unique created tables            41
Models                           34
Test methods discovered          121 (discovery only)
Laravel API routes parsed        79
Frontend API contracts parsed    78
Missing frontend contracts       0
Composer manifest/lock           FRESH
```

Final controlled packager smoke for this checkpoint: SHA-256 `a33e2397584fd8165063c179dfe6412c2f92407c1c6eae16b9cca0f6fd5c7d1c`, byte-identical PASS, unzip integrity PASS. Synthetic build only; not final release evidence.

## 14. Iteration 15.2 verification update — 2 October 2026

Production acceptance and artifact-integrity automation were strengthened from the clean Iteration 15.1 checkpoint.

Changes verified at source/tooling level:

- new CSRF/session-aware `scripts/http_acceptance.sh` covering SPA root, health/readiness, unauthenticated boundaries, security headers, director login, principal read-only modules, logout, and session invalidation;
- CI generates ephemeral APP_KEY/demo/acceptance credentials and uses the destructive DB guard before `migrate:fresh`;
- CI acceptance can send an explicit production-style Host header while connecting to loopback;
- security-header regression coverage now includes production CSP and HTTPS-only HSTS;
- release manifest verifier now rejects unexpected files and symlinks in addition to size/hash tampering;
- CI package smoke verifies an extracted package and requires rejection of an injected unexpected PHP file;
- `bootstrap/cache` is not exempted from closed-world manifest verification.

Direct integrity smoke executed in this iteration:

```text
original package                    PASS
README size/hash tamper             REJECTED
public/backdoor.php injection       REJECTED
public symlink injection            REJECTED
provisioned runtime .env            ALLOWED
```

Runtime prerequisite escalation was attempted again. The local npm cache contains zero packages; `npm ci --offline` fails immediately with `ENOTCACHED`. Direct Composer binary download is unavailable in the runner, and no MySQL/PHP extension toolchain is present. Consequently no runtime-only mandatory gate is promoted to PASS.

Final clean-source inventory for Iteration 15.2 before controlled packager smoke:

```text
PHP source files                 156
PHP syntax errors                0
Migration files                  33
Schema::create declarations      41
Models                           34
Test methods discovered          122 (discovery only)
Laravel API routes parsed        79
Frontend API contracts parsed    78
Missing frontend contracts       0
Composer manifest/lock           FRESH
Workflow/config parsing          PASS
Shell/Python syntax              PASS
```

A clean online `npm ci` retry again did not complete within the runner/network window and produced no valid build evidence; any partial `node_modules` was removed. The offline attempt independently confirmed an empty npm cache and failed with `ENOTCACHED` for package tarballs. Runtime-only mandatory gates therefore remain pending.


---

## Iteration 15.3 verification update — 5 October 2026

| Gate | Result |
|---|---|
| Handoff ZIP SHA-256 | PASS — exact match to `26e39062f72b54d30dd0f525ac78d17f15cbb844e3dd656e36a03e3f0e24bb05` |
| ZIP integrity | PASS — `unzip -t` |
| PHP inventory/lint | PASS — 156 files / 0 syntax errors |
| Schema/model inventory | PASS — 33 migrations / 41 table creates / 34 models |
| Test discovery | 122 methods discovered — inventory only |
| Composer lock content hash | PASS — `dbf1fce00e5f0b540d001cb016176a70` |
| Frontend/backend API contract | PASS — 79 backend API routes / 78 frontend contracts / 0 missing |
| Runtime preflight | BLOCKED — Composer, PHP extensions, `pdo_mysql`, MySQL unavailable |
| Clean `npm ci` | BLOCKED — npm registry DNS `EAI_AGAIN` |
| Real Vite build | NOT EXECUTED — clean npm install did not complete |
| Release packager guard | PASS — refuses without `public/build/manifest.json` |
| MySQL / PHPUnit / Pint / production readiness | BLOCKED by runtime prerequisites |
| HTTP/browser acceptance | BLOCKED — runnable production application unavailable; browser probe also hit runner policy `ERR_BLOCKED_BY_ADMINISTRATOR` |
| FINAL package | NOT CREATED |

Iteration 15.3 also restored missing Laravel runtime placeholder files and refreshed the source checkpoint manifest. This is release-engineering remediation only; no completed business scope was reopened.

## Iteration 15.4A verification addendum — 5 October 2026

Acceptance-tooling regression is PASS at source/tooling level: all discovered PHP files lint cleanly, frontend/backend contract audit remains 79/78/0, release shell/Python tooling parses cleanly, workflow YAML parses, browser acceptance refuses missing credentials, HTTP acceptance refuses missing authenticated credentials, and forwarded-HTTPS HSTS checking was exercised through a local mock surface. Test discovery is now 123 methods after adding coverage for the forced-password admin command option. These results do not promote Composer install, real Vite build, MySQL 8 migration, PHPUnit, Pint, live production readiness, live HTTP acceptance, or real application browser E2E to PASS; those gates remain environment-blocked.

## Iteration 15.4B verification addendum — 5 October 2026

Browser acceptance coverage is now functional rather than route-only at the source/tooling level. The gate creates an ephemeral Viewer through the actual user-management UI, exercises forced-password onboarding, verifies restricted navigation and route redirects, requires 403 responses for admin and object-level report access, generates an immutable director report snapshot through the UI, validates its SHA-256, and verifies CSV/JSON/Evidence Pack exports including response hash binding. Notification Center transport UI is also exercised for realtime/fallback state. Browser screenshots, JSON evidence, and export payloads are retained as CI artifacts.

Static regression for this checkpoint: PHP lint PASS (158 files / 0 syntax errors), frontend/backend contract audit PASS (79 / 78 / 0 missing), shell/Python syntax PASS, GitHub workflow YAML PASS, and missing Viewer acceptance credentials fail closed as designed. These are not substitutes for live application browser evidence. Real Composer/npm/MySQL/Vite/PHPUnit/Pint/release-check/HTTP/browser gates remain pending in the current runner, so FINAL PASS remains undeclared.

---

## Iteration 15.4C — Acceptance Isolation / Evidence Integrity / Orchestration

**Checkpoint status:** source/release-gate tooling PASS; executable production acceptance remains pending.

Verified source-level additions:

- authoritative `scripts/final-gate.sh` gate sequence;
- guarded fresh-DB boundary before acceptance and guarded cleanup after successful acceptance;
- closed-world `scripts/acceptance_evidence.py` with artifact/source SHA-256 binding;
- final gate machine-readable PASS/FAIL evidence;
- GitHub release workflow convergence to the same repository orchestrator;
- release packager exclusion of `artifacts/`.

Controlled negative tests passed: altered acceptance export rejected, unexpected acceptance evidence rejected, unexpected extracted release file rejected, and missing orchestrator prerequisite produced failure evidence. Controlled deterministic package smoke produced two byte-identical archives and confirmed no acceptance artifacts were shipped.

These are tooling/control proofs only. They do not replace the still-mandatory clean Composer/npm, real Vite, MySQL 8, PHPUnit, Pint, production readiness, live HTTP, real browser, and final real-build packaging executions.


## Iteration 15.4D verification addendum — 5 October 2026

Local/manual and CI production-verification paths are now converged on `scripts/final-gate.sh`. `scripts/verify-release.sh` no longer implements a separate sequence, and `FINALIZE_AND_RUN_MAC.command --release-gate` delegates to the same gate before any macOS demo logic. A new execution-readiness doctor checks orchestration commands, mandatory environment presence, destructive DB acknowledgement, Linux/macOS SHA-256 tooling, and installed Playwright Chromium.

Controlled fail-closed regression used identical dummy acceptance inputs across all three release entrypoints. Each returned non-zero and wrote `final_gate.json` with `failed_phase = 00-execution-readiness-doctor`, correctly reflecting missing Composer/Playwright browser prerequisites in this runner. This proves entrypoint convergence; it is not production runtime PASS evidence. Clean Composer/npm/Vite/MySQL 8/PHPUnit/Pint/readiness/HTTP/browser/final-package gates remain open.

## Iteration 15.5 verification addendum — 5 October 2026

The authoritative `scripts/final-gate.sh` was executed and failed closed at `00-execution-readiness-doctor`, producing `final_gate.json` failure evidence. With all required ephemeral verification variables supplied, the doctor reduced the true prerequisite failure to missing Composer; the subsequent MySQL preflight independently confirmed missing `mbstring`, `dom`, `xml`, `xmlwriter`, and `pdo_mysql` while PHP 8.4.23, Node 22.16.0, and npm 10.9.2 passed.

Package bootstrap was investigated without weakening clean-install requirements. Registry/host connectivity is unavailable in the container, no local Composer/MySQL/Docker fallback exists, and the npm cache matches only 18 of 178 lockfile tarballs (160 unavailable), so `npm ci --offline` correctly fails `ENOTCACHED` and cannot serve as build evidence.

Browser tooling was remediated to accept `NADI_BROWSER_EXECUTABLE_PATH` for managed/system Chromium. `/usr/bin/chromium` exists, is executable, and can be launched headlessly through Playwright. A local HTTP navigation probe is nevertheless rejected by runner policy with `net::ERR_BLOCKED_BY_ADMINISTRATOR`, so live browser acceptance remains environment-blocked. This checkpoint does not promote Composer/npm/Vite/MySQL/PHPUnit/Pint/readiness/HTTP/browser/final-package gates to PASS.

## Iteration 15.6 verification addendum — 5 October 2026

The runtime prerequisites diagnosed in Iteration 15.5 are now represented by an executable release-host contract rather than manual assumptions. `scripts/release_environment.py` successfully validates canonical manifest alignment and emits secret-redacted machine-readable evidence for host commands/versions, PHP extensions, required environment presence, APP key shape, test/CI/verify database scope, raw PDO MySQL reachability/version, Playwright package version, and browser executable readiness.

Controlled negative verification performed for this checkpoint:

```text
package.json npm-engine drift                     REJECTED
release credential generation for nadi_production REJECTED
runtime evidence secret-value serialization       REJECTED / 0 values present
missing Composer/PHP extensions/MySQL runtime      FAIL-CLOSED at authoritative phase 00
```

`final_gate.json` now links the available runtime contract/environment evidence with SHA-256 on failure and includes their hashes on successful final evidence. GitHub Actions uses the same repository-owned ephemeral credential generator as local/manual verification, removing a previous duplicate implementation path.

Static regression after the changes:

```text
PHP source files                 158
PHP syntax errors                0
Migration files                  33
Schema::create declarations      41
Models                           34
Test methods discovered          123 (inventory only)
Laravel API routes parsed        79
Frontend API contracts parsed    78
Missing frontend contracts       0
Workflow YAML                    PASS
Release shell syntax             PASS
Release Python syntax            PASS
Release-host contract alignment  PASS
```

This addendum is source/tooling/bootstrap evidence only. The current runner still lacks Composer, required PHP extensions (`mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`), and actual MySQL 8 runtime. Therefore the mandatory clean install/build/database/test/acceptance/final-package gates remain open and no production FINAL ZIP is authorized.

## Iteration 15.7 verification addendum — 5 October 2026

CI evidence custody is now executable and fail-closed. The new `scripts/ci_evidence.py` binds gate evidence to the exact source checkpoint and verifies the complete mandatory runtime matrix, runtime contract/environment hashes, source manifest, existing acceptance evidence semantics, and the exact gate-produced release ZIP before a PASS bundle can become release-authorizable.

Controlled executable regression passed for both branches without converting synthetic evidence into runtime proof. The current runner's real final gate failure at phase `00-execution-readiness-doctor` was successfully bundled, verified, and ingested with `release_authorizable = false`. Tampered payloads, unexpected files at payload/root level, source mismatch, `--require-pass` on a failed run, and incomplete PASS runtime-gate matrices were rejected. A clearly synthetic PASS fixture exercised only the verifier's positive branch and was not used as release evidence.

The GitHub workflow now retains one unified source-bound CI artifact and includes the actual gate-produced release ZIP on PASS. No real external CI PASS artifact has yet been ingested, so Composer/npm/Vite/MySQL/PHPUnit/Pint/readiness/HTTP/browser/final-package production claims remain unpromoted and FINAL PASS remains false.

Iteration 15.7 final static regression remains clean: 158 PHP files / 0 syntax errors; 33 migrations / 41 `Schema::create` declarations / 34 models; 123 test methods discovered (inventory only); frontend/backend contract audit 79 / 78 / 0 missing; workflow YAML, shell syntax, and Python compilation PASS. The refreshed audited source manifest contains 234 entries and verifies cleanly.

## Iteration 15.8 verification addendum — 5 October 2026

External CI execution could not be truthfully initiated from this checkpoint because the progress ZIP contains no Git remote metadata and the GitHub connection available in this room exposes no NADI repository. No CI run or artifact was fabricated.

A release-authorization gap from Iteration 15.7 was closed. Generic PASS evidence is no longer sufficient to set production authorization. Production authorization now requires GitHub Actions provenance, the `NADI Release Gates` workflow, explicit `workflow_dispatch`, `refs/heads/main`, complete run/repository/commit/runner metadata, and a semantic non-`ci-gate` package version.

Controlled executable policy regression:

```text
source-bound synthetic PASS bundle             PASS (mechanics only)
workflow_dispatch/main semantic-version policy PASS
append-only ingestion                          PASS
expected repository match                      PASS
extracted closed-world package verification    PASS
final copy byte identity                       PASS
repository mismatch                            REJECTED
ordinary push PASS release authorization       REJECTED
ci-gate placeholder production authorization   REJECTED
```

The synthetic package existed only in a temporary regression directory and was destroyed. It is not NADI runtime acceptance evidence and is not the production final artifact.

The authoritative runtime truth is unchanged: no real external PASS bundle has been ingested. FINAL PASS and final production ZIP remain unauthorized.

Iteration 15.8 final static regression: 158 PHP files / 0 syntax errors; 33 migrations / 41 `Schema::create` declarations / 34 models; 123 test methods discovered (inventory only); frontend/backend contract audit 79 / 78 / 0 missing; workflow YAML, shell syntax, Python compilation, and source-bound production-authorization fixture PASS. The refreshed audited source manifest contains 237 entries and verifies cleanly. These results remain source/tooling evidence only until a real external PASS artifact is ingested.

## Iteration 15.9 verification addendum — 5 October 2026

Iteration 15.9 closes the operator handoff between a downloaded external GitHub Actions evidence artifact and the Iteration 15.8 release decision. The new `scripts/consume_external_ci.py` performs safe archive handling, unique bundle discovery, exact source-bound PASS verification, production authorization checks, expected-repository matching, append-only ingestion, staged release decision, final package SHA verification, and atomic final-output publication. The consumer itself is part of the CI source fingerprint set.

No accessible NADI GitHub repository or real external PASS artifact exists in this room. Therefore controlled fixture tests in this iteration validate only the handoff mechanism; they are not production runtime evidence. `FINAL PASS` remains withheld pending a real external `workflow_dispatch` PASS artifact from this exact checkpoint.

### Iteration 15.9 controlled regression result

```text
external artifact ZIP consumption          PASS (synthetic mechanism fixture only)
extracted directory consumption            PASS (synthetic mechanism fixture only)
wrong repository                            REJECTED
unexpected wrapper file                     REJECTED
path traversal archive member               REJECTED
partial final output on rejected input      NOT PRESENT
fixture final ZIP vs gate ZIP               BYTE-IDENTICAL
```

The controlled fixture is temporary and does not alter the release decision: no real external GitHub Actions PASS evidence has been ingested, so production `FINAL PASS` remains false.

## Iteration 15.10 verification addendum — 5 October 2026

Iteration 15.10 closes the source/tooling boundary between an authorized external-CI FINAL output and a production target. The new production-intake chain preserves custody of the FINAL release decision, external-CI consumption receipt, package checksum, exact release ZIP, and safely extracted closed-world release tree.

Controlled executable regression:

```text
valid FINAL directory deployment intake         PASS (fixture mechanics only)
production-host PHP intake verification         PASS
wrong expected repository                       REJECTED
unexpected FINAL directory file                 REJECTED
custody tamper                                  REJECTED
tampered deployment stopped before Composer     PASS
mock post-deploy readiness/security contract    PASS (fixture mechanics only)
mock missing HSTS                               REJECTED
```

`deploy-production.sh` now verifies authorized intake before release integrity/runtime/dependency/database phases. It requires final post-deploy verification after bringing the application online; failure returns NADI to maintenance mode and never performs an automatic database rollback. Successful post-deploy verification writes source/artifact-bound `POST_DEPLOY_VERIFICATION.json`.

These results are source/tooling regression only. No real external GitHub Actions PASS artifact has been ingested, no production host has been deployed, and no real target-environment post-deploy verification has run. Production FINAL PASS therefore remains false.

Iteration 15.10 final static regression:

```text
PHP source files                 159
PHP syntax errors                0
Migration files                  33
Schema::create declarations      41
Models                           34
Test methods discovered          123 (inventory only)
Laravel API routes parsed        79
Frontend API contracts parsed    78
Missing frontend contracts       0
Workflow YAML                    PASS
Release shell syntax             PASS
Release Python syntax            PASS
Audited source manifest          243 entries / PASS
```

## Iteration 15.11 verification addendum — 5 October 2026

Iteration 15.11 closes the evidence-custody gap after deployment. Iteration 15.10 produced `POST_DEPLOY_VERIFICATION.json`, but the raw logs/header/status artifacts it hashed were temporary. The post-deploy verifier now writes a closed-world evidence bundle containing all evidence required to independently reproduce the acceptance decision, plus `POST_DEPLOY_EVIDENCE_MANIFEST.json` with per-file SHA-256 and byte counts.

New `scripts/production_operational_acceptance.py` verifies the source-bound deployment envelope, FINAL/CI custody, extracted release manifest, expected repository, GitHub Actions provenance, post-deploy evidence manifest, complete check matrix, status-code evidence, HSTS/CSP/nosniff headers, scheduler evidence, and all recorded evidence hashes. Accepted evidence is ingested append-only and produces `PRODUCTION_OPERATIONAL_ACCEPTANCE.json` only after every gate passes.

Controlled executable regression:

```text
post-deploy script generated closed-world bundle   PASS (mock transport/runtime mechanics only)
closed-world bundle file set                       11 entries / PASS
operational acceptance from directory              PASS (fixture mechanics only)
operational acceptance from ZIP wrapper            PASS (fixture mechanics only)
ready.status tamper                                REJECTED
unexpected evidence file                           REJECTED
wrong expected repository                          REJECTED
ZIP path traversal                                 REJECTED
partial acceptance output on rejected evidence     NOT PUBLISHED
```

The mock/fixture evidence above exists only to exercise the source/tooling contract and was removed after regression. No real external CI PASS artifact, production deployment, or real production-host post-deploy evidence has been accepted in this room. Production operational acceptance and `FINAL PASS` remain false.

## Iteration 15.12 verification addendum — 5 October 2026

Release-closure handoff controls were implemented and regression tested without promoting fixture evidence. The exporter requires source-bound authorized deployment intake plus an accepted operational-evidence directory. The resulting bundle is closed-world, source/repository/version/CI-bound, secret-scanned, and can be emitted as a deterministic ZIP.

Controlled positive verification passed for directory and ZIP handoff forms. Independent verification also re-extracted the custodied FINAL ZIP and compared the complete release-tree hashes against the deployment envelope.

Controlled negative verification:

```text
tampered post-deploy evidence             REJECTED
unexpected closure file                   REJECTED
wrong expected GitHub repository          REJECTED
ZIP path traversal                        REJECTED
credential assignment in evidence         REJECTED
```

These results prove the closure mechanism only. They do not constitute external CI, production deployment, operational acceptance, or FINAL production PASS evidence.

Iteration 15.12 freeze-level static regression completed with 159 PHP source files / 0 syntax errors, 11 Python release scripts / 0 syntax errors, valid shell/workflow syntax, 79 Laravel routes / 78 frontend contracts / 0 missing contracts, and 248 audited source-manifest entries before final fingerprint-bound closure regression.


# Iteration 15.13 Verification — Release Closure Verification Matrix / Operator Handoff Hardening

**Date:** 5 October 2026  
**Decision:** SOURCE/TOOLING PASS; REAL RELEASE CLOSURE STILL PENDING

## Implemented verification controls

- canonical eight-gate release closure matrix in `config/release-closure-verification-matrix.json`;
- `scripts/release_closure_matrix.py` validates matrix structure and audited source hashes;
- without external evidence, the evaluator emits G01=PASS and G02–G08=`WAITING_EXTERNAL_EVIDENCE`;
- only a closure artifact independently accepted by `release_closure_handoff.py verify` can produce `RELEASE_CLOSURE_VERIFIED` with G01–G08 all PASS;
- operator handoff export is non-overwriting, closed-world and SHA-256 manifested;
- operator packet verifier binds the packet to the exact source manifest and exact matrix bytes;
- `scripts/final-gate.sh` now validates the matrix/source checkpoint during phase 00.

## Controlled regression

```text
waiting-state matrix                              PASS
controlled fully verified closure G01–G08        PASS (fixture only)
wrong expected repository                        REJECTED
operator output overwrite                        REJECTED
operator packet content tamper                    REJECTED
unexpected operator packet file                  REJECTED
synthetic-authoritative matrix policy mutation   REJECTED
```

The controlled closure fixture is test evidence for the mechanism only. It is not external CI evidence and is not a production authorization.

## Static inventory before final source-manifest regeneration

```text
PHP source files             159
PHP syntax errors            0
Python release scripts       12
Python syntax/compile        PASS
Shell syntax                 PASS
Workflow YAML                PASS
Laravel API routes           79
Frontend API contracts       78
Missing backend contracts    0
Test methods discovered      123
Expected source entries      252 (manifest self-excluded)
```

The audited source manifest is regenerated only after all Iteration 15.13 source/documentation/state changes are frozen. Real Composer/npm/MySQL/browser runtime gates remain governed by the existing FINAL-pass rule and are not promoted by this iteration.

## Final-gate phase-00 integration regression

The authoritative `scripts/final-gate.sh` was executed with verification-only non-production inputs after the 15.13 matrix was wired. The matrix/source checkpoint validation passed first, then the gate failed closed in `00-execution-readiness-doctor` because the runner still lacks the required runtime prerequisites. This confirms the new matrix check is not the blocker and does not weaken the existing environment gate. No runtime PASS is claimed.

## Iteration 15.14 — Release Archive Retention / Recovery Verification & Operational Closeout

### Scope

Post-G08 custody/recovery tooling only. The canonical release authorization matrix remains G01–G08. This iteration does not infer or generate external CI, production deployment, operational acceptance, or FINAL PASS evidence.

### Implemented verification contract

- `config/release-archive-policy.json` requires verified closure/operator provenance, closed-world SHA-256 archive integrity, source/repository/version binding, secret-leak protection, non-overwrite behavior, and recovery rehearsal before closeout.
- `scripts/release_archive.py create` accepts only a verified closure ZIP plus a verified `RELEASE_CLOSURE_VERIFIED` operator packet that binds the exact closure SHA-256.
- `verify` re-validates current source manifest, closure matrix, archive policy, repository/version, retention metadata, full closure deep verification, FINAL ZIP/release-tree recovery, and operator handoff.
- `rehearse` writes a source/archive-bound recovery receipt only after all archive/closure/operator checks pass.
- `closeout` requires the exact archive SHA-256 in the recovery receipt, matching provenance, all PASS recovery checks, and an ACTIVE retention window.
- Archive recovery does not replace MySQL restore rehearsal.

### Controlled regression

```text
archive creation                                   PASS
archive ZIP verification                           PASS
archive-only recovery rehearsal                    PASS
operational closeout                               PASS
same-input deterministic archive ZIP               PASS / byte-identical
archive file tamper                                REJECTED
unexpected archive file                            REJECTED
wrong repository                                   REJECTED
ZIP path traversal                                 REJECTED
secret assignment in otherwise-valid operator      REJECTED
past retention at creation                         REJECTED
recovery receipt archive SHA substitution          REJECTED
expired archive verification                       PASS / retention=EXPIRED
expired retention operational closeout             REJECTED
```

All controlled fixtures are non-authoritative mechanism tests.

### Current truthful decision

```text
SOURCE COMPLETE                    YES
RELEASE CANDIDATE                  YES
REAL EXTERNAL CI PASS              NOT AVAILABLE
REAL PRODUCTION DEPLOYMENT         NOT EXECUTED
REAL OPERATIONAL ACCEPTANCE        NOT AVAILABLE
REAL RELEASE CLOSURE               NOT AVAILABLE
REAL RETAINED RELEASE ARCHIVE      NOT AVAILABLE
REAL OPERATIONAL CLOSEOUT          NOT AVAILABLE
FINAL PASS                         NOT YET
```

### Iteration 15.14 freeze inventory target

```text
PHP source files             159
PHP syntax errors            0
Python release scripts       13
Python syntax/compile        PASS
Shell syntax                 PASS
Workflow YAML                PASS
Laravel API routes           79
Frontend API contracts       78
Missing backend contracts    0
Test methods discovered      123
Audited source entries       256 (manifest self-excluded)
```

Final fingerprint-bound archive/recovery regression is executed only after this documentation/state is frozen and the audited source manifest is regenerated. Controlled runtime fixtures remain non-authoritative.

## Iteration 15.16 — External runtime target binding verification

Iteration 15.16 adds a production target identity boundary on top of the 15.15 source freeze. The target contract is source-bound and checkpoint-bound and requires an explicit operator confirmation of the real GitHub target. The CI evidence path now records and requires repository ID, GitHub server URL, workflow ref and workflow SHA in addition to the existing repository/ref/event/run provenance.

Controlled regression passed for target request generation, target bind/verify, CI provenance matching, target-bound external handoff creation/verification and post-handoff tamper rejection. Unconfirmed binding, CI repository-ID mismatch, CI server mismatch, workflow-ref mismatch, consumer execution without a target binding and a binding that violates the target policy were rejected.

The authoritative final gate was executed after the 15.16 changes. Source-freeze verification and the eight-gate release matrix both PASS before runtime readiness. The gate then fails honestly on the existing environment blockers: Composer is unavailable; PHP `mbstring`, `dom`, `xml`, `xmlwriter`, and `pdo_mysql` are unavailable; browser executable readiness is unavailable to the gate; and MySQL connectivity/major-version checks cannot pass in this runner. No runtime PASS is claimed.

The connected GitHub context exposes no accessible NADI repository at this checkpoint. Therefore real repository ID binding and real workflow-dispatch evidence remain pending.

## Iteration 15.17 — GitHub repository onboarding / metadata-backed target binding verification

Iteration 15.17 adds a non-authorizing, closed-world GitHub onboarding package and strengthens production target binding so manually entered repository identity cannot become release authority by itself.

Implemented verification controls:

- repository metadata positive validation requires GitHub numeric `id`, matching `full_name` / `owner.login`, `main`, GitHub HTML/API URL identity, and active (not archived/disabled) repository state;
- `bind-metadata` creates the only production-authoritative binding source (`github_repository_api_metadata`);
- manual `bind` is retained as `TARGET_BOUND_MANUAL_NON_AUTHORIZING` and cannot pass production target verification;
- onboarding artifact is exact-checkpoint/source/workflow bound, closed-world, SHA-256 manifested, secret-scanned and non-authorizing;
- external handoff inherits the metadata-backed binding requirement;
- real GitHub Actions evidence must still match repository ID/server/ref/event/workflow/job/source identity.

The connected GitHub context currently exposes no accessible NADI repository. Therefore real target binding and real CI evidence remain pending and are not simulated as production evidence.

Pre-checkpoint freeze regression for Iteration 15.17 completed with 159 PHP source files / 0 syntax errors, 16 Python release scripts / compile PASS, valid shell/workflow syntax, 79 Laravel routes / 78 frontend contracts / 0 missing contracts, 123 discovered test methods, and 267 closed-world audited source entries. Final checkpoint-bound repository onboarding and metadata-backed target-binding regressions are executed only after the exact checkpoint archive is created.

A negative onboarding regression found and fixed one release-control defect: GitHub credential assignments were not part of the shared secret-scan regex. `source_freeze.py` now rejects `GITHUB_TOKEN`, `GH_TOKEN`, `GITHUB_PAT`, and `GITHUB_ENTERPRISE_TOKEN`; the controlled credential-leak fixture is rejected after manifest re-hashing, proving the secret guard rather than the integrity hash is the blocking control.

Checkpoint-bound controlled regression for Iteration 15.17 confirms metadata-backed target binding, deterministic onboarding package creation, and external-runtime handoff integration. Manual binding is non-authorizing and is rejected by authoritative target/handoff verification. Archived/disabled repository metadata, default-branch or GitHub URL mismatch, artifact tamper, extra files, path traversal, GitHub credential assignment, and CI provenance mismatch are rejected.

The authoritative final gate still passes source-freeze/matrix phase-00 controls before failing honestly on the existing execution environment prerequisites (Composer, PHP extensions, browser executable readiness and MySQL runtime). No external CI or production PASS is claimed.

## Iteration 15.18 verification — repository materialization boundary

Source/tooling verification was extended with deterministic Git repository materialization and commit-bound CI authority. Controlled regression demonstrates deterministic one-root `main` history, deterministic Git bundle/package output, source-freeze equivalence after clone, remote HEAD equality checks, and rejection of commit mismatch/tamper/extra-file/path-traversal/submodule metadata. These are mechanism tests only. No real repository materialization, GitHub workflow dispatch, or external runtime PASS is claimed.

Iteration 15.18 final static regression: 159 PHP source files / 0 syntax errors; 17 Python release scripts compile successfully; shell/workflow validation PASS; 79 backend routes / 78 frontend API contracts / 0 missing; 123 test methods discovered; 33 migrations / 41 `Schema::create` declarations / 34 models. The closed-world audited source set contains 271 entries before checkpoint packaging.

The authoritative Iteration 15.18 final-gate regression reaches `00-execution-readiness-doctor` only after source-freeze and release-matrix validation pass. It then fails closed on the pre-existing runner PHP-extension/runtime blockers; browser executable readiness is PASS. This confirms repository-materialization hardening introduced no new runtime blocker.
