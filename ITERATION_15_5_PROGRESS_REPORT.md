# NADI — Iteration 15.5 Runtime Gate Execution & Failure Triage

**Date:** 5 October 2026  
**Baseline:** Iteration 15.4D  
**Status:** **PASS — FAILURE TRIAGE / PORTABILITY REMEDIATION; FINAL RUNTIME GATES STILL ENVIRONMENT-BLOCKED**

## Scope

Iteration 15.5 executes the authoritative release gate against the current runner, separates environment-contract omissions from actual runtime blockers, and patches only release-tooling defects proven by executable evidence. No completed business domain, schema behavior, KPI logic, authorization invariant, or historical semantics were reopened.

## Authoritative gate execution

A direct invocation of:

```bash
bash scripts/final-gate.sh
```

failed closed at:

```text
00-execution-readiness-doctor
```

and wrote `artifacts/release-gate/final_gate.json` with `status=fail` and the exact failed phase. This is expected truthful behavior; no downstream PASS was inferred.

The first raw invocation included missing ephemeral environment variables. A second doctor run supplied safe verification-only values so environment-contract omissions could be separated from real tooling blockers.

## Confirmed runtime blockers

With all required gate environment variables supplied, the doctor/preflight evidence is:

```text
PHP                              PASS — 8.4.23
Node                             PASS — v22.16.0
npm                              PASS — 10.9.2
Python/Playwright module         PASS
System Chromium executable      PASS — /usr/bin/chromium
Composer CLI                     BLOCKED — missing
PHP mbstring                     BLOCKED — missing
PHP dom                          BLOCKED — missing
PHP xml                          BLOCKED — missing
PHP xmlwriter                    BLOCKED — missing
PHP pdo_mysql                    BLOCKED — missing
MySQL 8 runtime                  BLOCKED — unavailable
```

Outbound container connectivity to Packagist/npm/PyPI is unavailable. The local npm cache was inspected rather than assumed empty: only **18 of 178** lockfile tarball URLs are cache-matched, leaving **160** unavailable, so a truthful clean `npm ci --offline` cannot complete. The observed first failure was `ENOTCACHED` for a lockfile tarball.

No Composer binary or MySQL server/client executable exists elsewhere in the local filesystem, Docker is unavailable, and PHP development tooling (`phpize`, `php-config`) is absent. Therefore the missing PHP extensions cannot be legitimately compiled in this runner without external packages.

## Browser portability defect found and remediated

The previous doctor required Playwright's managed Chromium path even when an execution environment already supplied a valid Chromium-compatible browser. This produced an avoidable false blocker on managed runners.

Iteration 15.5 adds optional:

```text
NADI_BROWSER_EXECUTABLE_PATH
```

Behavior:

- when unset, existing Playwright-managed Chromium behavior is unchanged;
- when set, doctor requires the configured file to exist and be executable;
- browser acceptance launches that executable through Playwright;
- no browser test is skipped or downgraded.

Controlled evidence in this runner:

```text
Playwright import                         PASS
/usr/bin/chromium existence/executable   PASS
Chromium headless launch + about:blank   PASS
```

A real loopback navigation probe to `http://127.0.0.1:<port>/` fails with:

```text
net::ERR_BLOCKED_BY_ADMINISTRATOR
```

This proves the remaining local browser blocker is runner navigation policy, not missing browser tooling or the NADI acceptance launcher.

## Source/tooling changes

- `scripts/release-gate-doctor.sh`: supports an explicit managed Chromium executable without weakening acceptance requirements.
- `scripts/browser_acceptance.py`: supports the same executable path and validates it before launch.
- `docs/RELEASE_GATE_EXECUTION.md`: documents managed/system Chromium usage.
- `README.md`: aligns release prerequisites and clarifies that an external browser path does not bypass browser acceptance.

## Truthful gate state after triage

The following mandatory gates are **not promoted to PASS**:

```text
clean Composer install
clean npm ci
real Vite build
real public/build/manifest.json
actual MySQL 8 migrate:fresh
full PHPUnit on MySQL
Pint
production nadi:release-check
production HTTP acceptance
real application browser acceptance
real-build deterministic FINAL packaging
extracted FINAL closed-world verification
FINAL ZIP SHA-256
```

The blocker set is now precise and reproducible rather than conflating missing acceptance variables with runtime capability.

## Next checkpoint

**Iteration 15.6 — Runtime Environment Bootstrap Contract & CI-Executable Closure**

Resume by preserving the mandatory gate unchanged, improving only legitimate environment/bootstrap reproducibility and CI execution evidence. Do not fake dependencies, substitute MariaDB/SQLite for MySQL 8 release evidence, reuse dirty dependency trees as clean-install proof, or reopen business scope without a runtime-proven source defect.

## Regression before checkpoint freeze

The 15.5 source/tooling changes were revalidated before checkpoint packaging:

```text
PHP source lint (all PHP files)        158 / 0 syntax errors
Release shell syntax                   PASS
Release Python syntax                  PASS
GitHub workflow YAML                   PASS
Frontend/backend API contract          PASS — 79 / 78 / 0 missing
System Chromium headless launch        PASS
Invalid browser executable path        REJECTED as required
npm lockfile cache coverage audit      18 / 178 available; 160 missing
Source checkpoint manifest             226 entries; full SHA-256 verification PASS
```

These are source/tooling and diagnostic results only; they do not substitute for the blocked mandatory runtime gates listed above.
