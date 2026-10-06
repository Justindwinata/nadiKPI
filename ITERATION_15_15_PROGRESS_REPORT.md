# NADI — Iteration 15.15 Progress Report

**Iteration:** 15.15 — Final Source Freeze / External Runtime Execution Handoff  
**Date:** 5 October 2026  
**Decision:** SOURCE FREEZE TOOLING PASS; REAL EXTERNAL EXECUTION HANDOFF NOT YET BOUND TO A REAL GITHUB REPOSITORY

## Objective

Freeze the source-release candidate without reopening business scope, make post-freeze source mutation explicitly invalidating, and provide one closed-world outbound handoff that can carry the exact checkpoint into the authoritative external GitHub Actions runtime.

## Implemented

- added `config/source-freeze-policy.json`;
- added `scripts/source_freeze.py` with `verify-source`, `create-handoff`, and `verify-handoff`;
- added `docs/EXTERNAL_RUNTIME_EXECUTION_HANDOFF.md`;
- wired `scripts/source_freeze.py verify-source --repo-root .` into phase 00 of `scripts/final-gate.sh` before environment/bootstrap work;
- source-freeze validation requires every release-critical lockfile, release contract, workflow and custody script to remain present in `AUDITED_SOURCE_RC_MANIFEST.sha256`;
- external handoff binds the exact checkpoint ZIP SHA-256, audited source-manifest SHA-256/entry count, final-gate/workflow fingerprints, runtime contract, closure matrix, freeze policy, expected repository and optional semantic release version;
- external handoff is closed-world and SHA-256 manifested, non-overwriting, safe-extraction guarded and credential/private-key scanned;
- any source mutation after handoff requires a new iteration, regenerated audited source manifest, new checkpoint ZIP and new handoff.

## Authority boundary

The handoff does not create `FINAL PASS` evidence. The only production-authoritative execution remains:

```text
GitHub Actions
workflow_dispatch
refs/heads/main
explicit semantic release version
scripts/final-gate.sh
```

A green external workflow must still be consumed by the existing source-bound CI evidence/decision path before deployment authorization.

## Current external target state

No verified NADI GitHub `owner/repository` identity or repository remote is available in the current checkpoint/environment. Therefore no real external handoff is fabricated. Controlled handoff fixtures use a non-production repository identity only to test mechanism behavior.

## Controlled regression target

The final freeze regression must prove:

```text
source-freeze verify                         PASS
release-critical file omitted from manifest REJECTED
source mutation after checkpoint             REJECTED
handoff create/verify directory              PASS (fixture only)
handoff create/verify ZIP                    PASS (fixture only)
wrong expected repository                    REJECTED
handoff content tamper                       REJECTED
unexpected handoff file                      REJECTED
ZIP path traversal                           REJECTED
credential/private-key leakage               REJECTED
same-input handoff ZIP                       byte-identical
final-gate phase 00 freeze check              PASS before environment blocker
```

Controlled fixtures are mechanism tests only and must be deleted before checkpoint freeze.

## Truthful status

```text
SOURCE IMPLEMENTATION             COMPLETE
SOURCE HARDENING                  COMPLETE
SOURCE FREEZE CONTRACT            IMPLEMENTED
SOURCE RELEASE CANDIDATE          YES
REAL GITHUB TARGET BOUND          NO
REAL EXTERNAL CI PASS             NOT AVAILABLE
REAL PRODUCTION DEPLOYMENT        NOT EXECUTED
FINAL PASS                        NOT YET
```

## Controlled regression result

```text
source-freeze verify                          PASS
source-manifest entries                      260
handoff directory verify                     PASS (fixture only)
handoff ZIP verify                           PASS (fixture only)
same-input handoff ZIP                       byte-identical
wrong repository                             REJECTED
handoff tamper                               REJECTED
unexpected file                              REJECTED
credential leak                              REJECTED
ZIP traversal                                REJECTED
source mutation                              REJECTED
release-critical manifest omission           REJECTED
final-gate freeze/matrix phase                PASS before existing environment blocker
```

No controlled artifact is retained as release evidence.

## Freeze discrepancy corrected before checkpoint

Final artifact verification exposed transient Python bytecode under `scripts/__pycache__`. The cache was removed and the source-freeze verifier was hardened to reject `__pycache__`, `.pyc`, `vendor`, `node_modules`, and `artifacts` paths from the audited source manifest. The checkpoint fingerprint is regenerated after this correction; no transient bytecode is part of the frozen source.

The freeze verifier was additionally hardened to **closed-world source verification**: every eligible file present in the checkpoint must be listed in `AUDITED_SOURCE_RC_MANIFEST.sha256`, and every listed file must exist with the recorded hash. Unlisted source files are rejected; only explicitly transient/dependency/runtime paths are excluded.
