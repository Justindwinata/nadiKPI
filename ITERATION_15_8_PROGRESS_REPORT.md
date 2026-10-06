# NADI — Iteration 15.8 External CI Runtime Execution, Evidence Import & Release Decision

**Date:** 5 October 2026  
**Baseline:** Iteration 15.7  
**Status:** **PASS — EXTERNAL CI RELEASE-DECISION BOUNDARY CLOSED; REAL EXTERNAL RUNTIME PASS ARTIFACT NOT YET AVAILABLE**

## Scope

Iteration 15.8 does not reopen business functionality and does not convert synthetic evidence into production runtime proof. It closes the decision boundary that must be used after a real external CI run has produced a source-bound PASS bundle.

## External CI availability check

The Iteration 15.7 checkpoint contains no `.git` directory or remote metadata. The GitHub connection available in this room exposes no NADI repository to execute against. Therefore no real GitHub Actions run can truthfully be launched or downloaded from this environment in this checkpoint.

The project remains RELEASE CANDIDATE until a real external CI artifact is supplied from the exact source checkpoint.

## Authorization gap discovered

Iteration 15.7 correctly distinguished PASS and FAIL gate evidence, but an ingested PASS bundle could still be marked `release_authorizable=true` without requiring production-specific CI provenance. That was too permissive because a local/synthetic PASS fixture or ordinary push run could satisfy the generic PASS schema.

Iteration 15.8 separates **valid PASS evidence** from **production release authorization**.

## Changes

### Production CI authorization policy

`scripts/ci_evidence.py` now records and validates additional GitHub provenance including event name, ref, actor, and run number. Production authorization requires:

- GitHub Actions provenance;
- workflow `NADI Release Gates`;
- explicit `workflow_dispatch`;
- `refs/heads/main`;
- complete run/repository/commit/runner provenance;
- a semantic release version, not `ci-gate`;
- the complete source-bound PASS runtime-gate matrix and release ZIP requirements already established in Iteration 15.7.

A push or pull-request PASS can still be retained as valid CI evidence, but it is not production release-authorizable.

### Versioned workflow dispatch

`.github/workflows/release-gates.yml` now requires a `release_version` input for manual dispatch. Manual production runs reject versions that are not semantic release versions. Non-dispatch runs retain the `ci-gate` package label and therefore cannot be promoted to production FINAL.

### Final release decision engine

Added `scripts/release_decision.py`.

The script accepts only append-only ingested PASS evidence and then:

1. re-runs source-bound CI evidence verification with `--require-pass` semantics;
2. enforces the production authorization policy;
3. requires the expected GitHub repository identity explicitly;
4. requires exactly one semantic-version gate ZIP;
5. extracts the ZIP and executes its own `scripts/verify_release_manifest.php` closed-world verifier;
6. checks release-manifest version against the package filename;
7. copies the exact CI gate ZIP byte-for-byte to `NADI-LSP-MIGAS-FINAL-<version>.zip`;
8. writes SHA-256 and `FINAL_RELEASE_DECISION.json` binding the final artifact to source manifest, CI bundle, ingestion receipt, repository/run provenance, and package digest.

No repackaging occurs after CI.

### Decision implementation source binding

The CI evidence source fingerprint set now also binds `scripts/release_decision.py`, preventing a PASS bundle from being trusted after the release-decision policy implementation has changed.

### Documentation

Added `docs/EXTERNAL_CI_RELEASE_DECISION.md` and aligned CI ingestion/README guidance with the production authorization boundary.

## Controlled executable regression

A synthetic PASS fixture was used only to exercise the decision mechanics. It is not runtime acceptance evidence.

Positive policy path:

```text
workflow_dispatch / main / semantic version       ACCEPTED by policy fixture
source-bound PASS bundle verify                    PASS
append-only ingestion                              PASS
production release_authorizable                    true
expected GitHub repository match                   PASS
extracted release closed-world verification        PASS
final named package                                CREATED in temp fixture only
final package bytes vs gate package                BYTE-IDENTICAL
```

Negative policy paths:

```text
wrong expected repository                          REJECTED
ordinary push PASS                                 NOT release-authorizable
ci-gate placeholder version                        NOT release-authorizable
```

The temporary fixture is destroyed after regression and is not retained or labeled as the NADI production release.

## Runtime truth

No real external `workflow_dispatch` PASS artifact exists in this room. Therefore all mandatory runtime claims remain governed by the last real execution evidence: the available local runner is blocked by missing Composer/PHP extensions/MySQL 8/package-registry connectivity and browser loopback policy.

No production final ZIP is created by this checkpoint.

## Next checkpoint

**Iteration 15.9 — External Execution Handoff / Real PASS Artifact Consumption**

If a real GitHub Actions evidence artifact becomes available, verify and ingest it against this exact source checkpoint and use `scripts/release_decision.py`. If the real run fails, patch only the defect proven by its bound phase/evidence and rerun. If no external run is available, preserve RELEASE CANDIDATE and do not fabricate FINAL evidence.

## Final checkpoint regression

After all Iteration 15.8 source/documentation changes and source-manifest regeneration:

```text
PHP source files                 158
PHP syntax errors                0
Migration files                  33
Schema::create declarations      41
Models                           34
Test methods discovered          123 (inventory only)
Laravel API routes parsed        79
Frontend API contracts parsed    78
Missing frontend contracts       0
Workflow YAML                    PASS
Release shell syntax             PASS
Release Python syntax            PASS
Production authorization fixture PASS (synthetic policy mechanics only)
Audited source manifest          237 entries / PASS
```

No real external CI PASS artifact was available; `FINAL PASS` remains false.
