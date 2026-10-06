# NADI — Iteration 15.16 Progress Report

**Iteration:** 15.16 — External Runtime Target Binding & Real CI Evidence Intake  
**Date:** 5 October 2026  
**Decision:** TARGET-BINDING TOOLING PASS; REAL GITHUB TARGET NOT AVAILABLE

## Objective

Preserve the frozen application/business scope while tightening the external execution boundary so that production-authoritative CI evidence must originate from one explicitly bound GitHub repository identity, not merely a matching `owner/repository` string.

## Implemented

- added `config/external-runtime-target-policy.json`;
- added `scripts/external_runtime_target.py` with `request`, `bind`, and `verify` modes;
- added `docs/EXTERNAL_RUNTIME_TARGET_BINDING.md`;
- target bindings include GitHub server URL, repository full name, numeric repository ID, default branch, required ref/event, workflow path/name/hash, job, source-manifest SHA-256, source entry count, exact checkpoint SHA-256 and optional semantic release version;
- `scripts/ci_evidence.py` now records GitHub repository ID, server URL, workflow ref and workflow SHA in CI provenance and requires them for GitHub Actions evidence;
- `scripts/consume_external_ci.py` now requires `--target-binding` (or `NADI_EXTERNAL_RUNTIME_TARGET_BINDING`) and verifies CI provenance against the bound target before append-only ingestion or final release decision;
- CI/source binding set now includes the target-binding policy/tooling;
- source freeze is re-issued as Iteration 15.16 because release-control source changed; business/application scope remains unchanged.

## Authority rule

`EXTERNAL_RUNTIME_TARGET_REQUEST.json` is explicitly non-authorizing. A real binding may only be created from the real GitHub repository identity. The numeric repository ID must not be guessed or synthesized.

A production-authoritative external run must match:

```text
provider       = github-actions
server_url     = https://github.com
repository     = bound owner/repository
repository_id  = bound numeric GitHub repository ID
ref            = refs/heads/main
event_name     = workflow_dispatch
workflow       = NADI Release Gates
workflow_ref   = <repo>/.github/workflows/release-gates.yml@refs/heads/main
job            = verify
source         = exact frozen checkpoint/source-manifest
```

## Current real target state

The connected GitHub account currently exposes no accessible NADI repository to this environment. Therefore no real target binding, workflow dispatch, real external CI PASS, or real PASS artifact is fabricated.

## Truthful state

```text
SOURCE IMPLEMENTATION             COMPLETE
BUSINESS/APPLICATION SCOPE        FROZEN
RELEASE-CONTROL SOURCE            RE-FROZEN AT 15.16
EXTERNAL TARGET CONTRACT          IMPLEMENTED
REAL GITHUB TARGET BOUND          NO
REAL EXTERNAL CI PASS             NOT AVAILABLE
REAL PRODUCTION DEPLOYMENT        NOT EXECUTED
FINAL PASS                        NOT YET
```

## Controlled regression result

```text
PHP source lint                               159 / 0 errors
Python release scripts                       15 parse PASS
shell/workflow syntax                        PASS
frontend/backend API contract                79 / 78 / 0 missing
test methods discovered                      123
source freeze closed-world                    PASS
controlled target request                    WAITING_EXTERNAL_TARGET_BINDING / non-authorizing
controlled target bind + verify              PASS (fixture only)
unconfirmed target binding                   REJECTED
repository ID mismatch in CI provenance      REJECTED
server URL mismatch in CI provenance         REJECTED
workflow_ref mismatch in CI provenance       REJECTED
consumer without target binding              REJECTED
external handoff + target binding verify     PASS (fixture only)
post-handoff target-binding tamper            REJECTED
wrong target-policy server                   REJECTED
final-gate source freeze                      PASS
final-gate release matrix                     PASS
final-gate runtime readiness                  BLOCKED by existing environment prerequisites
```

The controlled `example-org/nadi-lsp-migas` identity is mechanism-only test data and is not retained as real release authority.
