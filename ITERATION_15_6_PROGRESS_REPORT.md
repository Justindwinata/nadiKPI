# NADI — Iteration 15.6 Runtime Environment Bootstrap Contract & CI-Executable Closure

**Date:** 5 October 2026  
**Baseline:** Iteration 15.5  
**Status:** **PASS — RELEASE-HOST/CI BOOTSTRAP CONTRACT CLOSED; FINAL RUNTIME GATES STILL ENVIRONMENT-BLOCKED**

## Scope

Iteration 15.6 does not add or reopen business functionality. It converts the runtime prerequisites diagnosed in Iteration 15.5 into one executable, machine-readable release-host contract shared by local/manual execution and CI. It also removes duplicated ephemeral credential generation from GitHub Actions.

## Changes

### Executable release-host contract

Added `config/release-environment.json` and `scripts/release_environment.py`.

The contract checker intentionally uses only Python standard-library functionality so it can execute before Composer/npm project dependencies are installed. It validates and records:

- canonical source constraint alignment with `composer.json`, `package.json`, and `requirements-e2e.txt`;
- required command availability;
- PHP version and mandatory extensions;
- Composer major version;
- Node/npm versions against the project engine contract;
- Python minimum version;
- valid 32-byte base64 Laravel `APP_KEY` shape;
- required acceptance environment-variable presence without serializing values;
- verification database naming and destructive acknowledgement;
- raw PDO reachability to actual MySQL and required MySQL major version 8;
- pinned Playwright package and Chromium executable availability.

The authoritative final gate now writes:

```text
artifacts/release-gate/runtime_contract.json
artifacts/release-gate/runtime_environment.json
```

and binds both to final/failure release evidence with SHA-256.

### Shared ephemeral credential generation

Added `scripts/generate-release-gate-env.sh`.

The helper:

- generates a random 32-byte Laravel APP key;
- generates strong ephemeral acceptance/forced-password/Viewer credentials;
- sets destructive acknowledgement only for an explicitly test/CI/verify-scoped database name;
- supports shell-source and GitHub environment-file output;
- never generates or prints the database password;
- refuses production-like database names.

`.github/workflows/release-gates.yml` now uses this repository-owned helper instead of maintaining a separate credential-generation implementation.

### Doctor convergence

`scripts/release-gate-doctor.sh` now delegates to the same executable environment contract checker. `scripts/final-gate.sh` records contract evidence and detailed runtime-environment evidence before any clean dependency install.

The final PASS evidence now additionally binds:

```text
runtime_environment_evidence_sha256
runtime_environment_contract_sha256
```

Failure evidence references the available contract/environment evidence and their hashes.

### Artifact hygiene and documentation

- `/artifacts` is now explicitly ignored by source control as well as excluded from production packaging.
- Added `docs/RUNTIME_BOOTSTRAP.md` with safe local/CI bootstrap procedure.
- Updated `docs/RELEASE_GATE_EXECUTION.md` and `README.md` to describe the executable contract and shared credential generator.

## Controlled regression evidence

```text
release contract source alignment                  PASS
intentional package-engine drift                  REJECTED
production database credential generation         REJECTED
verification database credential generation       PASS
runtime evidence secret serialization             0 detected
final-gate failure evidence linkage                PASS
current-runner final gate                          FAIL-CLOSED at phase 00 as expected
workflow YAML parse                                PASS
release shell syntax                               PASS
release Python syntax                              PASS
PHP source lint                                    158 / 0 errors
frontend/backend API contract                      79 / 78 / 0 missing
migration files                                    33
Schema::create declarations                        41
models                                             34
test methods discovered                            123 (inventory only)
```

The current runner's structured environment evidence still correctly reports the real blockers:

```text
Composer CLI / Composer major      FAIL — unavailable
required PHP extensions            FAIL — mbstring/dom/xml/xmlwriter/pdo_mysql unavailable
actual MySQL connectivity          FAIL — MySQL runtime unavailable
actual MySQL major 8               FAIL — cannot be established without runtime
```

PHP, Node/npm, Python, source-contract alignment, APP key validation, verification DB scope/acknowledgement, pinned Playwright package, and configured system Chromium remain valid in the controlled probe.

## Truthful release state

The following mandatory gates are **not** promoted to PASS:

```text
clean Composer install
clean npm ci
real Vite build / public/build/manifest.json
actual MySQL 8 migrate:fresh
full PHPUnit on MySQL
Pint
production nadi:release-check
live HTTP acceptance
real application browser acceptance
real-build deterministic FINAL package
extracted FINAL closed-world verification
FINAL ZIP SHA-256
```

No synthetic/stub dependency execution is used as runtime evidence.

## Next checkpoint

**Iteration 15.7 — CI Gate Execution & Runtime Evidence Ingestion**

Continue from this checkpoint by attempting the authoritative gate on a host/CI runner that satisfies the new contract, ingesting executable gate artifacts, and fixing only source defects proven by that run. Do not reopen completed business scope and do not declare FINAL PASS unless the authoritative gate reaches its real end-to-end PASS state.
