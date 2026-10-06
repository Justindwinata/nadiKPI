# NADI — Iteration 15.4C Acceptance Isolation, Evidence Integrity & Final Gate Orchestration

**Date:** 5 October 2026  
**Baseline:** Iteration 15.4B  
**Status:** **PASS — SOURCE/RELEASE-GATE TOOLING CHECKPOINT; REAL RUNTIME FINAL PASS STILL OPEN**

## Scope

Iteration 15.4C is restricted to production acceptance isolation, tamper-evident acceptance evidence, release-package hygiene, and one fail-closed final gate orchestration path. No completed NADI business domain was reopened.

## Changes

### 1. Acceptance database isolation

Added `scripts/final-gate.sh` as the authoritative executable gate orchestrator.

The orchestrator now enforces these boundaries in order:

1. runtime preflight and API contract audit;
2. clean Composer install;
3. clean `npm ci` and real Vite build;
4. real MySQL 8 assertion and destructive verification-DB guard;
5. clean MySQL migration, PHPUnit-on-MySQL, and Pint;
6. production readiness check;
7. **second guarded `migrate:fresh` before HTTP/browser acceptance** so PHPUnit residue cannot influence acceptance;
8. ephemeral acceptance identities only after that reset;
9. HTTP and browser acceptance against one production-like server;
10. acceptance evidence generation and verification;
11. **post-acceptance guarded `migrate:fresh`** to remove ephemeral users/reports from the verification database;
12. deterministic real-build packaging twice;
13. extracted closed-world release-manifest verification, including negative extra-file rejection;
14. final gate evidence JSON with package/evidence/source SHA-256 values.

A missing mandatory environment variable is fail-closed and produces `artifacts/release-gate/final_gate.json` with the failed phase.

### 2. Tamper-evident browser acceptance evidence

Added `scripts/acceptance_evidence.py`.

A successful acceptance evidence set is closed-world and must contain exactly:

```text
browser_acceptance.json
dashboard-desktop.png
dashboard-mobile.png
viewer-dashboard.png
viewer-report.png
report-export.csv
report-export.json
report-export.zip
```

The tool validates the browser acceptance JSON, requires zero recorded console/page/request/HTTP diagnostic failures, validates the JSON export and Evidence Pack ZIP, rejects symlinks/unexpected files, and creates:

```text
ACCEPTANCE_EVIDENCE_MANIFEST.json
ACCEPTANCE_EVIDENCE_MANIFEST.sha256
```

The evidence manifest records SHA-256 and byte length for every artifact and binds the evidence to fingerprints of:

```text
AUDITED_SOURCE_RC_MANIFEST.sha256
composer.lock
package-lock.json
scripts/browser_acceptance.py
scripts/http_acceptance.sh
scripts/final-gate.sh
.github/workflows/release-gates.yml
```

Therefore acceptance evidence becomes invalid if either an artifact or a bound source/gate file changes after the run.

### 3. Release artifact hygiene fix

`scripts/package_release.py` now explicitly excludes `artifacts/`.

This closes a release-engineering leak where browser screenshots, acceptance JSON, or CI gate evidence could otherwise be included in a production source ZIP after successful acceptance.

### 4. CI orchestration convergence

`.github/workflows/release-gates.yml` now keeps only environment/tool provisioning outside the repository gate and delegates the application/release sequence to:

```text
bash scripts/final-gate.sh
```

Browser evidence and final-gate evidence are uploaded with `if: always()` after the orchestrator. This prevents CI and local/manual finalization logic from drifting into different mandatory gate definitions.

### 5. Minor acceptance cleanup

Removed a duplicate `routes` key in the in-memory browser evidence initializer. No product behavior changed.

## Controlled verification completed in this runner

The real application runtime is still unavailable here, so the authoritative final gate could not be promoted to runtime PASS. The parts that can be executed honestly were verified:

- `scripts/final-gate.sh` Bash syntax: PASS.
- Python compile for acceptance evidence, browser acceptance, packager, and contract audit tooling: PASS.
- Missing mandatory final-gate environment input: fail-closed PASS; failure evidence JSON created.
- Acceptance evidence generation from a controlled complete evidence set: PASS.
- Acceptance evidence verification: PASS.
- Acceptance evidence single-file tamper test: correctly REJECTED.
- Acceptance evidence unexpected-file test: correctly REJECTED.
- Controlled synthetic Vite packaging smoke: PASS.
- Two controlled package outputs: byte-identical PASS.
- Package contains no `artifacts/` paths: PASS.
- `unzip -t` on both controlled packages: PASS.
- Extracted release manifest verification: PASS.
- Extracted extra `public/unexpected.php`: correctly REJECTED.
- Extracted artifact after removing the injected file: PASS again.

Controlled package smoke SHA-256 during this checkpoint:

```text
781641b1b3854ce47376a548d8908bebdca8af1eb84016939e4ef964d2633456
```

This digest is **not** final release evidence because the Vite build used for this local packager regression was synthetic.

## Runtime truthfulness

No claim is made that the authoritative `scripts/final-gate.sh` has completed against the real NADI runtime in this runner. Existing environment blockers still prevent clean Composer/npm/MySQL/browser execution here.

Therefore:

```text
SOURCE COMPLETE / RELEASE CANDIDATE
FINAL PASS = NOT AUTHORIZED
FINAL PRODUCTION ZIP = NOT CREATED
```

## Next checkpoint

**Iteration 15.4D — Local/CI Runtime Runner Convergence & Execution Readiness**

Next work should converge the existing macOS/local finalization entrypoint and documentation onto the authoritative final-gate orchestrator, validate the failure/recovery UX, and remove any remaining alternative path that could bypass or contradict the mandatory gate sequence. It must not weaken or skip any runtime requirement.
