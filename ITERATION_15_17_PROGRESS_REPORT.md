# NADI — Iteration 15.17 Progress Report

**Iteration:** 15.17 — GitHub Repository Onboarding Package / Target Binding Execution Readiness  
**Date:** 6 October 2026  
**Decision:** ONBOARDING / METADATA-BACKED TARGET-BINDING TOOLING PASS — REAL GITHUB REPOSITORY STILL NOT AVAILABLE

## Objective

Preserve the frozen business/application scope while converting the unresolved external-target step into a deterministic GitHub repository onboarding path. Production-authoritative target binding must no longer rely on a manually typed numeric repository ID; it must be backed by metadata exported from the real GitHub repository.

## Implemented

- added `scripts/github_repository_onboarding.py`;
- added `docs/GITHUB_REPOSITORY_ONBOARDING.md`;
- onboarding package is closed-world, SHA-256 manifested, deterministic under `SOURCE_DATE_EPOCH`, source/checkpoint-bound and explicitly non-authorizing;
- onboarding package carries the exact frozen checkpoint, `EXTERNAL_RUNTIME_TARGET_REQUEST.json`, metadata requirements, target policy and runbook;
- `external_runtime_target.py` adds `metadata-verify` and `bind-metadata`;
- repository metadata validation checks numeric `id`, `full_name`, `owner.login`, default branch, GitHub HTML/API URLs, archived/disabled state and optional visibility value;
- metadata-backed bindings record repository metadata SHA-256 and normalized repository/API identity;
- the legacy manual `bind` path is demoted to `TARGET_BOUND_MANUAL_NON_AUTHORIZING`;
- target verification requires `binding_source=github_repository_api_metadata` plus repository-metadata provenance fields;
- external-runtime handoff verification inherits the stronger metadata-backed target requirement;
- source freeze is re-issued as Iteration 15.17 because release-control tooling changed; business/application scope remains unchanged.

## Real GitHub state

The connected GitHub context currently exposes no accessible repositories. No NADI repository identity, numeric repository ID, workflow dispatch, or external CI PASS is invented.

## Authority rule

```text
Onboarding package                  NON-AUTHORIZING
Manual repository-id binding        NON-AUTHORIZING
GitHub REST metadata-backed binding REQUIRED FOR PRODUCTION AUTHORITY
GitHub Actions provenance           MUST MATCH SAME repository_id/server/ref/workflow/job
Source/checkpoint fingerprint       MUST MATCH 15.17 frozen checkpoint
```

## Current truthful state

```text
SOURCE IMPLEMENTATION              COMPLETE
BUSINESS/APPLICATION SCOPE         FROZEN
RELEASE-CONTROL SOURCE             RE-FROZEN AT 15.17
GITHUB ONBOARDING CONTRACT         IMPLEMENTED
REAL GITHUB REPOSITORY AVAILABLE   NO
REAL TARGET BINDING                NO
REAL EXTERNAL CI PASS              NOT AVAILABLE
FINAL PASS                         NOT YET
```

## Regression plan before checkpoint freeze

- GitHub repository metadata positive validation;
- archived/disabled/wrong-owner/wrong-server/default-branch rejection;
- manual binding confirmed non-authorizing;
- metadata-backed binding + verification against exact 15.17 checkpoint;
- onboarding directory/ZIP verification and deterministic ZIP identity;
- onboarding tamper/extra-file/path-traversal/secret-material rejection;
- external handoff creation only with metadata-backed binding;
- CI provenance mismatch rejection;
- full PHP/Python/shell/workflow/API-contract/static freeze regression;
- closed-world audited source manifest verification;
- authoritative final gate must still pass phase-00 source/matrix checks then stop only on pre-existing runtime environment blockers.

## Freeze regression — pre-checkpoint

```text
PHP source files                    159
PHP syntax errors                   0
Python release scripts              16
Python compile                      PASS
Shell syntax                        PASS
Workflow YAML                       PASS
Laravel API routes                  79
Frontend API contracts              78
Missing backend contracts           0
Test methods discovered             123
Closed-world audited source entries 267
Source freeze verification          PASS
```

Repository-metadata mechanism regression before checkpoint freeze:

```text
valid GitHub repository metadata    PASS
archived repository                 REJECTED
owner.login mismatch                REJECTED
```

The production-authoritative binding/onboarding ZIP regression is performed only after the exact Iteration 15.17 checkpoint ZIP is frozen, so checkpoint/source hashes cannot be inferred from a temporary working tree.

## Defect found during negative regression

A controlled secret-injection regression exposed that the existing handoff secret scanner did not recognize GitHub credential variable names. The scanner was hardened to reject `GITHUB_TOKEN`, `GH_TOKEN`, `GITHUB_PAT`, and `GITHUB_ENTERPRISE_TOKEN` assignments in addition to the existing application/database/browser credentials and private-key markers. The controlled onboarding artifact containing a GitHub token assignment is now rejected.

## Controlled checkpoint-bound regression result

Using the exact frozen 15.17 checkpoint candidate and controlled `example-org/nadi-lsp-migas` GitHub metadata fixture:

```text
metadata-backed target bind + verify              PASS (fixture only)
manual binding state                              NON-AUTHORIZING
manual binding authoritative verify               REJECTED
onboarding directory/ZIP verify                   PASS
same-input onboarding ZIP                         PASS / byte-identical
archived repository metadata                      REJECTED
disabled repository metadata                      REJECTED
wrong default branch                              REJECTED
wrong GitHub HTML/API identity                    REJECTED
onboarding file tamper                            REJECTED
unexpected onboarding file                        REJECTED
onboarding ZIP path traversal                     REJECTED
GitHub credential assignment after re-manifest    REJECTED
metadata-backed external runtime handoff           PASS (fixture only)
manual-binding external runtime handoff            REJECTED
matching CI repository provenance                 PASS (fixture only)
repository/server/ref/event/job mismatch           REJECTED
```

The shared secret guard was hardened after the controlled GitHub credential fixture exposed missing token-variable coverage. No controlled fixture is retained as production authority.

## Authoritative gate convergence

With complete verification-only environment variables, `scripts/final-gate.sh` passes the Iteration 15.17 source-freeze and release-matrix checks, then fails closed in `00-execution-readiness-doctor` on the pre-existing runner blockers: Composer, PHP `mbstring/dom/xml/xmlwriter/pdo_mysql`, browser executable readiness, and unavailable MySQL runtime/connectivity. Iteration 15.17 introduces no new runtime blocker and no runtime PASS is claimed.
