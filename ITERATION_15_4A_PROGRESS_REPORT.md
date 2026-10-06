# NADI — Iteration 15.4A Acceptance Gate Completeness Hardening

**Date:** 5 October 2026  
**Baseline:** Iteration 15.3  
**Status:** **PASS — SOURCE/ACCEPTANCE TOOLING CHECKPOINT; RUNTIME FINAL GATES STILL OPEN**

## Scope

Iteration 15.4A does not reopen business functionality. It closes two release-engineering gaps discovered while preparing the mandatory runtime acceptance path:

1. `scripts/http_acceptance.sh` previously allowed the authenticated flow to be skipped when acceptance credentials were absent and still exited successfully.
2. The repository had no executable browser-render acceptance gate even though browser E2E is mandatory before `FINAL PASS`.

## Changes

- HTTP acceptance is now fail-closed unless authenticated acceptance credentials are provided.
- A public/unauthenticated-only mode remains available only through the explicit `NADI_ACCEPTANCE_ALLOW_UNAUTHENTICATED_ONLY=true` override and cannot be confused with full production acceptance.
- HTTPS proxy simulation support was added through `NADI_ACCEPTANCE_FORWARDED_PROTO`; HSTS is mandatory when the effective request is HTTPS.
- Added `scripts/browser_acceptance.py` using pinned Playwright tooling.
- Browser acceptance covers authenticated login, the principal NADI application routes, desktop render, mobile navigation, global horizontal-overflow smoke, keyboard skip-link focus, logout, console/page/network errors, and optional forced-password flow.
- Added `requirements-e2e.txt` with pinned Playwright version.
- `nadi:create-admin` now supports `--force-password-change`, enabling deterministic creation of a temporary-password acceptance identity without demo seeding.
- GitHub release gates now create both normal and forced-password acceptance identities, verify HSTS over trusted forwarded HTTPS, install pinned browser tooling, and execute browser-render acceptance before deterministic package smoke.
- Added a feature test for `--force-password-change` semantics. Runtime PHPUnit execution remains pending because the current runner still lacks the mandatory PHP/MySQL toolchain.

## Verification completed in this runner

- PHP syntax for all discovered PHP files: PASS (158 files, 0 syntax errors).
- Frontend/backend contract audit: PASS (79 Laravel API routes, 78 frontend contracts, 0 missing).
- Bash syntax for release shell scripts: PASS.
- Python syntax for browser/package/contract tooling: PASS.
- GitHub Actions workflow YAML parsing: PASS.
- Browser gate missing-credential fail-closed behavior: PASS.
- HTTP acceptance missing-credential fail-closed behavior: PASS using a local mock acceptance surface.
- HSTS branch and explicit unauthenticated-only override behavior: PASS using a local mock acceptance surface.
- Test inventory after the new command behavior test: 123 discovered test methods.

## Gates intentionally not promoted to PASS

The current execution environment still blocks clean Composer install, required PHP extensions, MySQL 8, clean npm registry access, the real Vite build, PHPUnit-on-MySQL, Pint, production readiness, live HTTP acceptance, and real browser acceptance against the NADI application. Chromium navigation in this runner remains policy-blocked.

No `FINAL PASS` and no FINAL production ZIP are claimed.

## Next checkpoint

**Iteration 15.4B — Browser Functional Scenario Coverage & Release Evidence Consolidation**

Expand the browser gate from route/render smoke into the minimum decision-oriented functional scenarios required by the handoff (forced password, key forms/status states, report/export initiation, notification behavior, access boundaries) while preserving fail-closed release behavior. Runtime PASS will still require an environment capable of installing dependencies and running MySQL 8 plus a real browser.
