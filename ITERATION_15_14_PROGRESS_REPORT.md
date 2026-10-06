# NADI — Iteration 15.14 Progress Report

**Iteration:** 15.14 — Release Archive Retention / Recovery Verification & Operational Closeout  
**Date:** 5 October 2026  
**Scope:** post-release custody/recovery tooling only; no business-scope changes and no new release authorization gate

## Objective

Close the operational custody boundary after G01–G08 without weakening the existing release decision semantics. A verified release closure and operator packet must be retainable, independently re-verifiable, recoverable, and closeable without manual evidence reconstruction.

## Implemented

- `config/release-archive-policy.json` with source-controlled fail-closed archive requirements and no hard-coded official retention duration.
- `scripts/release_archive.py`:
  - `create`: builds non-overwriting closed-world archive from verified closure ZIP + verified operator handoff;
  - `verify`: validates archive manifest/digest, source/matrix/policy/repository/version/retention binding and re-runs closure/operator verification;
  - `rehearse`: proves archive-only recovery/reverification and writes append-only recovery receipt;
  - `closeout`: emits `OPERATIONAL_CLOSEOUT_READY` only for the exact archive with PASS recovery receipt and ACTIVE retention window.
- deterministic archive ZIP when identical inputs and explicit `SOURCE_DATE_EPOCH` are supplied.
- secret-leak, symlink/path-traversal, closed-world, hash/size and overwrite protections.
- `docs/RELEASE_ARCHIVE_AND_RECOVERY.md` plus release-closure/database-backup boundary documentation.

## Controlled regression

```text
archive create from verified closure/operator packet    PASS
archive directory/ZIP verification                     PASS
archive-only recovery rehearsal                        PASS
operational closeout                                    PASS
deterministic same-input archive ZIP                   PASS (byte-identical)
archive file tamper                                     REJECTED
unexpected archive file                                REJECTED
wrong expected repository                              REJECTED
ZIP path traversal                                      REJECTED
credential assignment in valid operator packet          REJECTED
past retention at archive creation                     REJECTED
recovery receipt archive-SHA substitution               REJECTED
expired archive integrity verification                 PASS / reports EXPIRED
operational closeout on expired retention              REJECTED
```

Controlled fixtures prove the mechanism only. They are not real release evidence and must not be promoted.

## Release semantics

The canonical release closure matrix remains **G01–G08**. Archive retention/recovery is post-G08 custody and does not create FINAL PASS.

The repository does not claim an official LSP Migas retention duration. `retention_until_utc` must come from approved organizational policy. Storage WORM/immutability, encryption, replication, legal hold, and disposition remain infrastructure/organizational controls outside this repository.

## Truthful status

```text
SOURCE COMPLETE                    YES
RELEASE CANDIDATE                  YES
ARCHIVE/RECOVERY TOOLING           PASS (controlled source/tooling regression)
REAL EXTERNAL CI PASS              NOT AVAILABLE
REAL PRODUCTION DEPLOYMENT         NOT EXECUTED
REAL OPERATIONAL ACCEPTANCE        NOT AVAILABLE
REAL RELEASE CLOSURE               NOT AVAILABLE
REAL RETAINED RELEASE ARCHIVE      NOT AVAILABLE
FINAL PASS                         NOT YET
```

## Next checkpoint

Iteration 15.15 — Final Source Freeze / External Runtime Execution Handoff.

## Freeze target

```text
PHP source files        159
PHP syntax errors       0
Python release scripts  13
Python syntax/compile   PASS
Shell syntax            PASS
Workflow YAML           PASS
Laravel API routes      79
Frontend API contracts  78
Missing backend         0
Test methods discovered 123
Source manifest         256 entries (self-excluded)
```

The checkpoint is frozen only after source-manifest regeneration, matrix waiting-state validation, final-fingerprint-bound archive/recovery regression, deterministic checkpoint ZIP comparison, `unzip -t`, and extracted source-manifest verification.
