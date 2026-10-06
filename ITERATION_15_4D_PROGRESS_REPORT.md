# NADI — Iteration 15.4D Local/CI Runtime Runner Convergence & Execution Readiness

**Date:** 5 October 2026  
**Baseline:** Iteration 15.4C  
**Status:** **PASS — RELEASE-RUNNER CONVERGENCE / SOURCE TOOLING; REAL RUNTIME FINAL PASS STILL OPEN**

## Scope

Iteration 15.4D removes alternate/manual production-verification paths that could drift from the authoritative release gate. No completed business domain, schema behavior, KPI logic, or authorization invariant was reopened.

## Changes

### 1. One authoritative release pipeline

`scripts/final-gate.sh` remains the only production release-verification implementation.

- `.github/workflows/release-gates.yml` executes it directly.
- `scripts/verify-release.sh` is now a compatibility wrapper that delegates directly to it.
- `FINALIZE_AND_RUN_MAC.command --release-gate` delegates directly to it.
- macOS default/demo mode remains available but is explicitly SQLite/synthetic **local demo only** and cannot authorize FINAL PASS.

This removes duplicated release sequencing from the old manual verifier and macOS launcher.

### 2. Execution-readiness doctor

Added `scripts/release-gate-doctor.sh`.

Before the authoritative gate begins dependency/database work, the doctor checks:

- required shell/runtime commands;
- required release-gate environment variables without printing secret values;
- destructive DB acknowledgement equality;
- SHA-256 provider availability on Linux (`sha256sum`) or macOS (`shasum -a 256`);
- Playwright Python + installed Chromium tooling.

The final gate now starts with phase:

```text
00-execution-readiness-doctor
```

Missing prerequisites therefore fail earlier and produce machine-readable failure evidence.

### 3. Linux/macOS checksum portability

Final artifact/evidence/source SHA-256 calculation in `scripts/final-gate.sh` no longer assumes GNU `sha256sum`; it falls back to macOS `shasum -a 256`.

### 4. Failure-evidence resilience

If Python itself is unavailable, the final-gate trap now has a shell fallback that still writes a minimal `final_gate.json` failure record. This prevents a missing evidence-tool dependency from hiding the fact that the gate failed.

### 5. Documentation convergence

Added `docs/RELEASE_GATE_EXECUTION.md` and updated README, security, architecture, and deployment/release documentation so they all identify `scripts/final-gate.sh` as the authoritative release gate. The new runbook documents required environment variables, local/macOS entrypoints, CI convergence, gate ordering, and failure/retry semantics.

## Controlled verification in this runner

The real runtime remains unavailable, so this checkpoint validates runner convergence rather than claiming production execution.

Controlled invocation with complete dummy acceptance environment but known missing local prerequisites produced:

```text
direct scripts/final-gate.sh                rc=1
scripts/verify-release.sh wrapper            rc=1
FINALIZE_AND_RUN_MAC.command --release-gate rc=1
```

All three produced the same failed phase:

```text
00-execution-readiness-doctor
```

The doctor correctly reported the current real blockers in this runner, including missing Composer and unavailable Playwright Chromium installation, while preserving failure evidence.

Additional static regression:

- Bash syntax for all release shell/command entrypoints: PASS.
- Unknown/skip-like argument to `final-gate.sh`: correctly REJECTED with `00-argument-validation` failure evidence.
- PHP source lint: PASS — 158 files / 0 syntax errors.
- Frontend/backend API contract audit: PASS — 79 backend API routes / 78 frontend contracts / 0 missing.
- GitHub workflow YAML parse: PASS.
- Wrapper convergence assertions: PASS.
- No production release wrapper contains an alternate mandatory-gate sequence: PASS.

## Runtime truthfulness

This iteration does **not** promote any environment-blocked gate to PASS. Clean Composer/npm, real Vite build, actual MySQL 8, PHPUnit-on-MySQL, Pint, live production readiness, HTTP acceptance, browser acceptance, real-build deterministic packaging, and extracted final artifact verification still require an execution environment capable of running the authoritative gate end to end.

Therefore:

```text
SOURCE COMPLETE / RELEASE CANDIDATE
FINAL PASS = NOT AUTHORIZED
FINAL PRODUCTION ZIP = NOT CREATED
```

## Next checkpoint

**Iteration 15.5 — Runtime Gate Execution & Failure Triage**

Resume by attempting the authoritative `scripts/final-gate.sh` in the available environment, investigating environment bootstrap options without weakening requirements, and fixing only defects proven by executable failures. Do not expand business scope.
