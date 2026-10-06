# NADI — Iteration 15.4B Browser Functional Scenario Coverage & Release Evidence Consolidation

**Date:** 5 October 2026  
**Baseline:** Iteration 15.4A  
**Status:** **PASS — SOURCE/ACCEPTANCE TOOLING CHECKPOINT; REAL RUNTIME/BROWSER PASS STILL OPEN**

## Scope

Iteration 15.4B continues release acceptance only. It does not reopen completed business modules or change production domain behavior.

The goal is to upgrade browser acceptance from route/render smoke into decision-oriented functional verification that can run against the isolated MySQL verification database in CI.

## Changes

### Browser functional coverage

`scripts/browser_acceptance.py` now requires and exercises an ephemeral Viewer identity in addition to the director acceptance identity.

The browser gate now covers:

- authenticated director login and principal route rendering;
- desktop/mobile render and global overflow smoke;
- skip-link keyboard focus;
- Notification Center open/render with accepted transport state (`Realtime tersambung` or fallback synchronization);
- immutable report snapshot creation through the UI;
- validation of the 64-character report SHA-256;
- authenticated CSV, JSON, and Evidence Pack ZIP export validation, including content type, `X-NADI-Report-Hash`, non-empty payload, and ZIP signature;
- persistence of exported report artifacts into the browser evidence directory;
- creation of an ephemeral Viewer through the real Users & Access UI;
- first-login forced-password workflow for that Viewer;
- Viewer navigation allowlist/restricted-menu assertions;
- SPA redirect checks for restricted routes;
- HTTP 403 verification for restricted admin API access;
- object-level 403 verification against the director's executive report snapshot;
- Viewer-owned report creation with export controls absent and export API rejected with 403;
- Viewer Notification Center transport smoke;
- logout/session UI path;
- optional separately provisioned forced-password director flow retained from 15.4A.

### CI evidence consolidation

`.github/workflows/release-gates.yml` now:

- generates unique ephemeral Viewer credentials per workflow run;
- passes those credentials only through the ephemeral GitHub Actions environment;
- clears the prior browser evidence directory before execution;
- uploads `artifacts/browser-acceptance` after the browser gate using `actions/upload-artifact@v7`, including failure evidence through `if: always()`;
- keeps deterministic final-package smoke downstream of the browser gate.

The official `actions/upload-artifact` repository currently exposes v7 as a supported current major; no bespoke artifact upload mechanism was introduced.

## Evidence emitted by a successful browser run

Expected files include:

```text
artifacts/browser-acceptance/browser_acceptance.json
artifacts/browser-acceptance/dashboard-desktop.png
artifacts/browser-acceptance/dashboard-mobile.png
artifacts/browser-acceptance/viewer-dashboard.png
artifacts/browser-acceptance/viewer-report.png
artifacts/browser-acceptance/report-export.csv
artifacts/browser-acceptance/report-export.json
artifacts/browser-acceptance/report-export.zip
```

Failure execution writes `browser_acceptance_failure.txt`; the CI upload step is configured with `if: always()` so failure evidence is not silently lost.

## Verification completed in this runner

- Python syntax/compile for browser, packager, and API-contract tooling: PASS.
- Bash syntax for release shell scripts: PASS.
- GitHub Actions workflow YAML parsing: PASS.
- PHP syntax: PASS — 158 files, 0 syntax errors.
- Frontend/backend API contract audit: PASS — 79 Laravel API routes / 78 frontend contracts / 0 missing.
- Browser acceptance missing Viewer identity: fail-closed PASS (non-zero exit with explicit required-variable message).
- Workflow wiring contains all mandatory Viewer acceptance variables and browser evidence upload step: PASS by static inspection.

## Runtime truthfulness

This runner still cannot promote the browser scenarios to executable application PASS because the mandatory application runtime remains unavailable: Composer CLI and required PHP extensions are missing, MySQL 8 is unavailable, clean npm dependency resolution remains blocked by registry/network access, and browser navigation against the application is restricted by runner policy.

Therefore this checkpoint proves the browser acceptance **contract/tooling**, not the live NADI browser result.

No FINAL production ZIP is produced and `FINAL PASS` remains unauthorized.

## Next checkpoint

**Iteration 15.4C — Acceptance Isolation, Evidence Integrity & Final Gate Orchestration**

Focus next on making browser evidence itself tamper-evident/reproducibly summarized, checking acceptance-run isolation/cleanup semantics, and ensuring one release-gate orchestration path can distinguish source/tooling PASS from executable runtime PASS without weakening any existing mandatory gate.
