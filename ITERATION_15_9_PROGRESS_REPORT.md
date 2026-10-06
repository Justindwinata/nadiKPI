# NADI — ITERATION 15.9 PROGRESS REPORT

**Iteration:** 15.9 — External Execution Handoff / Real PASS Artifact Consumption  
**Date:** 5 October 2026  
**Status:** SOURCE/TOOLING CHECKPOINT IN PROGRESS — REAL EXTERNAL PASS ARTIFACT NOT AVAILABLE IN THIS ROOM

## Objective

Close the operational gap between a downloaded external GitHub Actions evidence artifact and the already-defined Iteration 15.8 final release decision policy. This iteration must not fabricate external CI execution or production runtime PASS evidence.

## External execution availability

The carried source checkpoint contains no `.git` remote metadata. The connected GitHub account available in this room exposes no NADI repository, so a real `workflow_dispatch` run cannot be started or downloaded here. The application therefore remains RELEASE CANDIDATE until a real source-bound artifact is supplied from the external CI environment.

## Gap addressed

Iteration 15.8 defined how an already-extracted, already-ingested PASS bundle can authorize release. A real operator still had to manually extract a GitHub Actions artifact, identify the correct bundle root, run verify, choose an append-only ingestion destination, and then invoke the release decision. That left avoidable handoff/operator-error risk.

## Changes

### External CI artifact consumer

Added `scripts/consume_external_ci.py`.

The consumer accepts either:

- the ZIP downloaded from GitHub Actions; or
- an already-extracted CI evidence bundle directory.

It performs one fail-closed chain:

```text
safe input handling
→ unique bundle discovery
→ source-bound PASS verification
→ production authorization policy
→ expected repository match
→ append-only ingestion
→ final release decision in staging
→ final package hash verification
→ atomic final-output publication
```

### Archive safety

ZIP consumption rejects:

- absolute/path-traversal names;
- duplicate members;
- symlinks;
- unexpected files outside the discovered evidence bundle;
- excessive entry counts;
- oversized members / excessive total uncompressed size.

### Atomic FINAL publication

`dist-final` is not created incrementally. `release_decision.py` runs against a staging directory; only after the decision and package SHA-256 are verified is the complete directory atomically published. An ingestion receipt may remain append-only if a later decision failure occurs, but no partial FINAL directory is published.

### Consumption receipt

Successful real consumption produces `EXTERNAL_CI_CONSUMPTION.json`, binding:

- external artifact identity/hash;
- current source manifest hash;
- CI evidence bundle hash;
- append-only ingestion receipt hash;
- release-decision hash;
- final package name/hash/bytes;
- expected repository and GitHub CI provenance.

### Source binding

`scripts/consume_external_ci.py` is included in the CI source-fingerprint set. A bundle produced before or after a change to the production consumer cannot verify against the current checkpoint.

## Runtime truth

No real external PASS artifact is available in this room. Controlled synthetic fixtures may be used only to regression-test the consumption mechanism. They must never be retained or described as NADI production runtime evidence.

## Release state

```text
SOURCE COMPLETE
RELEASE CANDIDATE
REAL EXTERNAL CI PASS  NOT AVAILABLE
FINAL PASS             NOT AUTHORIZED
```

## Controlled consumer regression

A synthetic PASS artifact was created only to exercise the external-consumption mechanics. It was source-bound to the current checkpoint, used the required GitHub Actions provenance shape, and was destroyed after regression. It is not production runtime evidence.

Positive paths:

```text
GitHub artifact ZIP input                  PASS
extracted evidence directory input         PASS
source-bound CI verification               PASS
append-only ingestion                      PASS
production authorization policy            PASS (fixture mechanics only)
expected repository match                  PASS
staged release decision                     PASS
atomic final directory publication          PASS
final ZIP vs gate ZIP                       BYTE-IDENTICAL
consumption receipt                         PASS
```

Negative paths:

```text
wrong expected repository                  REJECTED
unexpected file in artifact wrapper        REJECTED
path-traversal ZIP member                   REJECTED
partial FINAL publication after rejection  NOT PRESENT
```

## Final checkpoint regression target

Before freezing Iteration 15.9, the checkpoint must still pass PHP lint, frontend/backend API contract audit, Python/shell/workflow syntax checks, audited source-manifest verification, consumer fixture regression against the final source manifest, deterministic progress-ZIP reproduction, `unzip -t`, and extracted source-manifest verification.

## Next checkpoint

**Iteration 15.10 — Production Deployment Artifact Intake & Post-Deploy Verification Contract**

This next step must remain downstream of the real external CI release decision. It may harden deployment intake/receipt/post-deploy verification tooling but must not bypass the missing real CI PASS evidence.

## Freeze inventory

The Iteration 15.9 source/tooling freeze is expected to retain the existing application inventory while adding only release-consumption tooling/documentation:

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
Audited source manifest          239 entries
```

A final regression is executed after the audited source manifest is regenerated; this section records source/tooling inventory only and does not claim runtime gates blocked by the current environment.
