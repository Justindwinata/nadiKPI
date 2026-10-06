# NADI — Iteration 15.7 CI Gate Execution & Runtime Evidence Ingestion

**Date:** 5 October 2026  
**Baseline:** Iteration 15.6  
**Status:** **PASS — CI EVIDENCE EXPORT/VERIFY/INGESTION CLOSED; REAL END-TO-END CI PASS EVIDENCE NOT YET AVAILABLE**

## Scope

Iteration 15.7 does not reopen business functionality and does not reinterpret static regression as production acceptance. It closes the evidence transport gap between an authoritative CI execution of `scripts/final-gate.sh` and the source checkpoint that must later decide whether a release can be authorized.

## Gap discovered

The Iteration 15.6 workflow uploaded browser and gate evidence but did not retain the gate-produced release ZIP as part of the same cryptographically bound artifact. A future CI PASS could therefore leave evidence and release package separated.

This checkpoint closes that gap.

## Changes

### Unified CI evidence bundle

Added `scripts/ci_evidence.py` with three modes:

```text
bundle
verify
ingest
```

The bundle is closed-world and contains:

```text
CI_EVIDENCE_BUNDLE.json
CI_EVIDENCE_BUNDLE.json.sha256
payload/release-gate/
payload/browser-acceptance/   (when produced)
payload/release/              (PASS only; exactly one release ZIP)
```

Every payload file is recorded by byte size and SHA-256.

### Source binding

A bundle is bound to the exact source checkpoint using SHA-256 fingerprints for:

- `AUDITED_SOURCE_RC_MANIFEST.sha256`;
- `composer.lock`;
- `package-lock.json`;
- `config/release-environment.json`;
- `scripts/final-gate.sh`;
- `scripts/acceptance_evidence.py`;
- `scripts/ci_evidence.py`;
- `.github/workflows/release-gates.yml`.

A bundle from another checkpoint is rejected.

### PASS authorization rules

A PASS bundle is accepted only when:

- `final_gate.json` is a genuine `nadi.final-gate.v1` PASS object;
- all 12 mandatory runtime gate entries are present and equal `pass`;
- runtime contract/environment SHA links match;
- acceptance evidence passes the existing source-bound/closed-world semantic verifier;
- current source-manifest SHA matches final gate evidence;
- exactly one release ZIP is present;
- its SHA matches the gate-recorded package digest;
- there are no unexpected payload/root files.

Failed gate bundles can still be verified and ingested for diagnosis, but always produce `release_authorizable = false`.

### CI provenance

When running under GitHub Actions, the bundle records and requires:

```text
repository
commit SHA
ref
run ID
run attempt
workflow
job
runner OS
runner architecture
```

The evidence layer does not serialize release credentials, APP keys, acceptance passwords, or database passwords.

### Workflow closure

`.github/workflows/release-gates.yml` now:

1. runs the authoritative final gate;
2. always builds and verifies one source-bound CI evidence bundle;
3. uploads the unified bundle using run ID + attempt;
4. retains raw release/browser diagnostics separately only on failure.

A successful bundle includes the actual gate-produced release ZIP instead of losing it after CI completion.

### Documentation

Added `docs/CI_EVIDENCE_INGESTION.md` and linked the workflow from release execution documentation and README.

## Controlled executable regression

### Real failure-path regression on current runner

The authoritative final gate was run with safe ephemeral verification variables. It correctly failed at:

```text
00-execution-readiness-doctor
```

The resulting real failure evidence was then:

```text
bundled   PASS
verified  PASS
ingested  PASS
release_authorizable = false
```

This preserves the known environment blocker without inventing runtime PASS.

### Negative verification

The ingestion verifier rejected all controlled negative cases:

```text
modified evidence payload                     REJECTED
unexpected payload file                       REJECTED
unexpected bundle-root file                   REJECTED
source checkpoint mismatch                    REJECTED
--require-pass against failed run              REJECTED
PASS gate missing mandatory runtime entry      REJECTED
```

### Synthetic PASS-path schema regression

A controlled synthetic fixture was used only to exercise the PASS branch of the evidence verifier. It carried a synthetic acceptance evidence set, synthetic final-gate PASS object, and synthetic ZIP and was clearly not used as runtime release evidence.

The fixture proved:

```text
all mandatory gate entries required            PASS
acceptance semantic verifier invoked           PASS
single package SHA binding                      PASS
closed-world bundle verification                PASS
PASS ingestion receipt                          PASS
post-ingestion re-verification                  PASS
```

This regression validates verifier behavior only. It does **not** promote any real runtime gate to PASS.

## Runtime truth

No real CI end-to-end PASS artifact is available in this environment. Current runner blockers from Iteration 15.6 remain truthful:

```text
Composer unavailable
required PHP extensions unavailable
actual MySQL 8 runtime unavailable
registry/network constraints
loopback browser navigation policy
```

Therefore the project remains:

```text
SOURCE COMPLETE
RELEASE CANDIDATE
FINAL PASS = NOT YET
```

## Next checkpoint

**Iteration 15.8 — External CI Runtime Execution, Evidence Import & Release Decision**

The next checkpoint should consume a real `nadi-ci-release-evidence-<run>-<attempt>` artifact produced on a compliant CI/host, verify it with `--require-pass`, ingest it against this exact source checkpoint, and patch only defects demonstrated by executable evidence. If the artifact is a genuine PASS, proceed to final release artifact authorization and final reporting. If it is FAIL, use the bound failed phase/evidence for targeted remediation and rerun.

## Final checkpoint regression

After all 15.7 source/documentation changes:

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
Audited source manifest          234 entries / PASS
```
