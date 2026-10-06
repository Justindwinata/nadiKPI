# NADI — Iteration 15.18 Progress Report

**Iteration:** 15.18 — Repository Materialization & Authoritative Workflow Dispatch Readiness  
**Date:** 6 October 2026  
**Decision:** SOURCE/TOOLING PASS — REAL GITHUB REPOSITORY MATERIALIZATION & WORKFLOW DISPATCH STILL EXTERNAL/PENDING

## Objective

Close the gap between a frozen source ZIP and the exact Git commit that must run in GitHub Actions. Repository identity alone is insufficient: production-authoritative CI must execute the deterministic Git commit materialized from the frozen checkpoint.

## Implemented

- added `config/repository-materialization-policy.json`;
- added `scripts/repository_materialization.py`;
- added `docs/GITHUB_REPOSITORY_MATERIALIZATION.md`;
- deterministic one-root commit on `main` with fixed author/committer identity and `SOURCE_DATE_EPOCH`;
- deterministic `SOURCE_GIT_BUNDLE.bundle` plus closed-world materialization package;
- bundle verification includes exact single advertised `refs/heads/main`, one-commit/root-history assertion, commit/tree fingerprint, and re-verification of source-freeze after bundle clone;
- repository materialization forbids `.git`, `.gitmodules`, symlinks, submodules, unmanifested files, and recognized secret material;
- GitHub remote confirmation requires repository metadata plus `GET /git/ref/heads/main` metadata and exact remote HEAD match;
- production target binding now requires `GITHUB_REPOSITORY_MATERIALIZATION_CONFIRMATION.json`;
- target binding carries `materialized_commit_sha`, materialization/bundle fingerprints, and remote-ref evidence fingerprint;
- CI provenance verification now requires `GITHUB_SHA == materialized_commit_sha`;
- CI consumption receipt carries the exact materialized commit;
- added `dispatch-readiness` command to verify repository/checkpoint/commit/version contract before operator triggers `workflow_dispatch`;
- GitHub onboarding package now carries the materialization policy and runbook.

## Controlled regression

```text
materialization create + verify                  PASS
same-input materialization ZIP                  PASS / byte-identical
same-input Git bundle                           PASS / byte-identical
bundle clone source-freeze                      PASS
single main ref / one root commit               PASS
remote GitHub main == expected commit           PASS (fixture only)
remote GitHub main mismatch                     REJECTED
target bind with remote confirmation            PASS (fixture only)
CI provenance exact commit                      PASS (fixture only)
CI provenance different commit                  REJECTED
unexpected package file                         REJECTED
bundle tamper                                    REJECTED
ZIP path traversal                              REJECTED
manifest-bound .gitmodules source               REJECTED
manual target binding                           NON-AUTHORIZING
```

Controlled GitHub metadata uses `example-org/nadi-lsp-migas` only to exercise policy. It is not production authority and is not retained as real target evidence.

## Real external state

The connected GitHub context still exposes no accessible repository. Therefore:

```text
REAL GITHUB REPOSITORY            NOT AVAILABLE
REAL REMOTE MAIN MATERIALIZATION  NOT EXECUTED
REAL TARGET BINDING               NOT CREATED
REAL WORKFLOW_DISPATCH            NOT EXECUTED
REAL EXTERNAL CI PASS             NOT AVAILABLE
FINAL PASS                        NOT YET
```

No repository ID, commit, workflow run, or CI PASS is invented.

## Freeze regression target

Before the final checkpoint is frozen:

- PHP syntax lint;
- Python compile;
- shell/workflow validation;
- frontend/backend API-contract audit;
- test-method inventory;
- closed-world audited source manifest regeneration and verification;
- authoritative final-gate phase-00 source/matrix verification;
- final checkpoint reproducibility;
- final checkpoint-bound materialization package reproducibility and verification.

## Freeze regression — final source candidate

```text
PHP source files                    159
PHP syntax errors                   0
Python release scripts              17
Python compile                      PASS
Shell syntax                        PASS
Workflow YAML                       PASS
Laravel API routes                  79
Frontend API contracts              78
Missing backend contracts           0
Test methods discovered             123
Migration files                     33
Schema::create declarations         41
Models                              34
Closed-world audited source entries 271
```

The final checkpoint and checkpoint-bound materialization package are produced only after this source state is hashed and verified.

## Authoritative final-gate convergence

With verification-only ephemeral credentials and the guarded `nadi_test` database name, `scripts/final-gate.sh` passes the Iteration 15.18 source-freeze and release-matrix checks, then fails closed in `00-execution-readiness-doctor` on the existing runner limitations. Browser executable readiness passes; required PHP extensions `mbstring`, `dom`, `xml`, `xmlwriter`, and `pdo_mysql` remain unavailable, and the runner still lacks the complete Composer/MySQL runtime required for later phases. No runtime PASS is claimed.
