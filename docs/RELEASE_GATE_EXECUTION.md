# NADI — Authoritative Release Gate Execution

## Purpose

NADI has exactly one production release-verification pipeline:

```bash
bash scripts/final-gate.sh
```

GitHub Actions, compatibility wrappers, and the macOS launcher must delegate to that script rather than reimplementing release checks. A local SQLite demo is intentionally separate and can never authorize `FINAL PASS`.

## Entry points

| Entry point | Purpose | Production authority |
| --- | --- | --- |
| `bash scripts/final-gate.sh` | Complete production release verification | **Authoritative** |
| `bash scripts/verify-release.sh` | Backward-compatible alias | Delegates to `final-gate.sh` |
| `./FINALIZE_AND_RUN_MAC.command --release-gate` | macOS convenience entrypoint | Delegates to `final-gate.sh` |
| `./FINALIZE_AND_RUN_MAC.command --doctor` | Readiness diagnostics only | No PASS authority |
| `./FINALIZE_AND_RUN_MAC.command --demo` | SQLite + synthetic local demo | **Never production evidence** |

Do not add a second release pipeline. New mandatory checks belong in `scripts/final-gate.sh` and should then be inherited by CI and wrappers automatically.

## Required environment contract

The executable host contract is defined by `config/release-environment.json` together with the canonical dependency manifests. See `docs/RUNTIME_BOOTSTRAP.md`. Before the authoritative gate runs, provide an isolated MySQL 8 verification database and ephemeral acceptance identities. The gate does not accept production data for destructive verification.

Required variables:

```text
APP_KEY
DB_DATABASE
NADI_VERIFY_DESTRUCTIVE_DATABASE
NADI_ACCEPTANCE_EMAIL
NADI_ACCEPTANCE_PASSWORD
NADI_BROWSER_FORCED_EMAIL
NADI_BROWSER_FORCED_PASSWORD
NADI_BROWSER_FORCED_NEW_PASSWORD
NADI_BROWSER_VIEWER_EMAIL
NADI_BROWSER_VIEWER_PASSWORD
NADI_BROWSER_VIEWER_NEW_PASSWORD
```

`NADI_VERIFY_DESTRUCTIVE_DATABASE` must equal `DB_DATABASE`; the repository's destructive-database assertion also requires a test/CI/verify-scoped database name.

The normal Laravel database connection variables (`DB_CONNECTION=mysql`, host, port, username, password) must point only to that verification database. Generate the ephemeral APP key/acceptance identities with `scripts/generate-release-gate-env.sh`; the helper refuses non-test/CI/verify database names and never generates the database password.

## Tooling prerequisites

The execution-readiness doctor is backed by `scripts/release_environment.py` and requires:

- Bash;
- PHP/Composer;
- Node/npm;
- Python 3;
- curl;
- unzip;
- `cmp`;
- SHA-256 tooling (`sha256sum` or macOS `shasum -a 256`);
- Playwright Python package and Chromium. By default Playwright's managed Chromium is used; environments with a managed/system browser may set `NADI_BROWSER_EXECUTABLE_PATH` to an executable Chromium-compatible binary.

Run diagnostics without changing database state:

```bash
bash scripts/release-gate-doctor.sh
```

The doctor validates source/host contract alignment, orchestration prerequisites, required environment presence, production-shaped APP key, verification-database naming/acknowledgement, required PHP extensions, Playwright version/browser availability, and raw PDO connectivity to actual MySQL major version 8. When `NADI_BROWSER_EXECUTABLE_PATH` is set, the configured executable must exist and be executable; real application browser navigation remains part of the later acceptance phase. `scripts/runtime-preflight.sh --mysql`, the Laravel MySQL assertion, destructive DB guard, Laravel readiness, HTTP acceptance, and browser acceptance remain mandatory later phases of the authoritative gate.

Through the final gate, phase 00 records `artifacts/release-gate/runtime_contract.json` and `runtime_environment.json`. The latter stores only boolean presence for secret variables, never their values.

## Gate ordering

`scripts/final-gate.sh` executes, fail-closed:

1. execution-readiness doctor;
2. PHP/Node/MySQL runtime preflight;
3. frontend/backend contract audit;
4. clean Composer install;
5. clean `npm ci`;
6. real Vite build and manifest assertion;
7. actual MySQL 8 + verification DB assertions;
8. guarded `migrate:fresh`;
9. PHPUnit forced to MySQL;
10. Pint;
11. production release readiness and Laravel cache checks;
12. second guarded database reset for acceptance isolation;
13. ephemeral acceptance identities;
14. production-like local server;
15. HTTP acceptance;
16. browser functional acceptance;
17. closed-world/source-bound acceptance evidence verification;
18. guarded post-acceptance database cleanup;
19. deterministic double packaging and byte comparison;
20. extracted closed-world release verification;
21. final machine-readable gate evidence with artifact SHA-256.

No phase may be skipped to obtain `FINAL PASS`.

## Failure and retry semantics

- Any failure returns non-zero and writes `artifacts/release-gate/final_gate.json` where tooling permits.
- If the temporary application server has started, the ERR/INT/TERM trap stops it before exit.
- A failure during HTTP/browser acceptance may leave test rows in the isolated verification database for diagnosis. This is safe because the next complete run executes a guarded `migrate:fresh` before acceptance again.
- The successful path performs an additional guarded reset after evidence capture so ephemeral acceptance users/reports do not remain.
- Failed package directories/evidence may remain for diagnosis, but the final package is not authorized unless the entire gate reaches the final PASS evidence phase.
- Never retry against a production database and never bypass `assert_verification_database.php`.

## macOS usage

The launcher defaults to local demo mode:

```bash
./FINALIZE_AND_RUN_MAC.command --demo
```

That path uses SQLite and synthetic data and is explicitly non-production.

To execute the same authoritative release gate used by CI:

```bash
./FINALIZE_AND_RUN_MAC.command --doctor
./FINALIZE_AND_RUN_MAC.command --release-gate
```

The release-gate path does not use the SQLite demo flow.

## CI convergence

`.github/workflows/release-gates.yml` prepares MySQL 8, PHP/Composer, Node/npm, and Playwright, generates ephemeral credentials through the repository-owned `scripts/generate-release-gate-env.sh`, then executes only:

```bash
bash scripts/final-gate.sh
```

Therefore local release verification and CI share the same mandatory orchestration logic and evidence schema.

## CI evidence export and ingestion

After the authoritative gate returns, CI creates one source-bound closed-world evidence artifact with `scripts/ci_evidence.py`. A PASS bundle contains the final gate evidence, browser acceptance evidence, and exactly one gate-produced release ZIP. A FAIL bundle remains diagnostic only.

To validate a downloaded successful CI artifact against the exact source checkpoint:

```bash
python3 scripts/ci_evidence.py verify \
  --bundle-dir /path/to/nadi-ci-release-evidence \
  --require-pass
```

To retain a validated run in a local evidence workspace:

```bash
python3 scripts/ci_evidence.py ingest \
  --bundle-dir /path/to/nadi-ci-release-evidence \
  --destination artifacts/ingested-ci/<run-id>-<attempt> \
  --require-pass
```

See `docs/CI_EVIDENCE_INGESTION.md` for the evidence schema, source binding, closed-world rules, and failure semantics.
