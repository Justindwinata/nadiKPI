# NADI — WORK STATE

**Current working checkpoint:** Iteration 15.18 — Repository Materialization & Authoritative Workflow Dispatch Readiness  
**Current release state:** SOURCE FROZEN / RELEASE CANDIDATE — MATERIALIZATION CONTRACT READY — WAITING REAL GITHUB REPOSITORY, REMOTE HEAD CONFIRMATION, TARGET BINDING & EXTERNAL CI

## Completed most recently

- Preserved all business/application scope and release custody hardening through Iteration 15.17.
- Added deterministic Git repository materialization from the exact frozen checkpoint.
- Added one-root `main` commit + Git bundle custody and independent source-freeze re-verification after clone.
- Production target binding now requires real GitHub repository metadata **and** remote `main` confirmation matching the materialized commit.
- GitHub Actions evidence must carry `GITHUB_SHA` equal to the bound materialized commit.
- Added authoritative workflow dispatch-readiness verification; readiness is explicitly not CI PASS.
- Controlled positive/negative regression passed; no controlled fixture is production authority.
- Connected GitHub context currently exposes no accessible NADI repository, so real materialization/target binding/workflow dispatch remains pending.

## Resume rule

When the user says **"lanjutkan"**, do not redo Iteration 15.3–15.18 unless a discrepancy is discovered. Continue from the final frozen 15.18 checkpoint.

If a real NADI GitHub repository becomes accessible, use only the deterministic materialization package from the exact 15.18 checkpoint, push its root `main` commit without amendment/rebase, capture repository + `git/ref/heads/main` metadata, create the remote materialization confirmation, create the metadata-backed target binding, run `dispatch-readiness`, then trigger the authoritative `NADI Release Gates` workflow using `workflow_dispatch` with the bound semantic release version.
