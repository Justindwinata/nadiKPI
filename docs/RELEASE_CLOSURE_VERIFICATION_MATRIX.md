# NADI — Release Closure Verification Matrix & Operator Handoff

## Purpose

This document removes operator ambiguity from the final NADI production-release chain. The matrix does **not** create runtime evidence. It maps already-existing evidence to an explicit authority, required state, and downstream decision.

The canonical machine-readable matrix is:

```text
config/release-closure-verification-matrix.json
```

The evaluator is:

```text
scripts/release_closure_matrix.py
```

## Canonical matrix

| Gate | Authority | Required state | Primary evidence |
|---|---|---|---|
| G01 Source checkpoint | Repository | PASS | `AUDITED_SOURCE_RC_MANIFEST.sha256` |
| G02 External CI FINAL | GitHub Actions | FINAL_PASS | `FINAL_RELEASE_DECISION.json` |
| G03 CI consumption | Trusted release host | FINAL_PASS | `EXTERNAL_CI_CONSUMPTION.json` |
| G04 Deployment intake | Trusted release host | AUTHORIZED_FOR_DEPLOYMENT | `DEPLOYMENT_INTAKE.json` |
| G05 Post-deploy verification | Production host | PASS | `POST_DEPLOY_EVIDENCE_MANIFEST.json`, `POST_DEPLOY_VERIFICATION.json` |
| G06 Operational acceptance | Trusted release host | ACCEPTED | `PRODUCTION_OPERATIONAL_ACCEPTANCE.json` |
| G07 Closure handoff | Trusted release host | HANDOFF_READY | `RELEASE_CLOSURE.json` + closure manifest |
| G08 Independent verification | Independent operator | PASS | verified exact closure artifact |

Every gate is fail-closed and blocks downstream promotion. Synthetic fixtures, mocks, local demo runs, generic push CI, or pull-request checks are never authoritative production evidence.

## Validate the matrix and source checkpoint

```bash
python3 scripts/release_closure_matrix.py validate --repo-root .
```

This validates the matrix structure and re-hashes every entry in `AUDITED_SOURCE_RC_MANIFEST.sha256`.

## Current checkpoint status without external evidence

```bash
python3 scripts/release_closure_matrix.py status --repo-root .
```

Expected source-checkpoint behavior before real external evidence exists:

```text
G01 = PASS
G02–G08 = WAITING_EXTERNAL_EVIDENCE
release_authorized = false
production_operationally_accepted = false
release_closure_verified = false
```

`WAITING_EXTERNAL_EVIDENCE` is not an error and is not a release PASS.

## Verify a real closure artifact

After a real release has completed authoritative external CI, deployment, post-deploy verification, operational acceptance, and release closure export:

```bash
python3 scripts/release_closure_matrix.py status \
  --repo-root . \
  --artifact /secure/archive/NADI-RELEASE-CLOSURE-<version>.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY"
```

The evaluator delegates deep custody verification to `release_closure_handoff.py`. Only a fully verified closure artifact can produce:

```text
status = RELEASE_CLOSURE_VERIFIED
G01–G08 = PASS
release_authorized = true
production_operationally_accepted = true
release_closure_verified = true
```

## Export the operator handoff snapshot

```bash
python3 scripts/release_closure_matrix.py export \
  --repo-root . \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --output-dir artifacts/operator-handoff/<run-id>
```

For a real verified closure, also supply `--artifact`.

The export contains:

```text
OPERATOR_HANDOFF.json
OPERATOR_HANDOFF.md
RELEASE_CLOSURE_VERIFICATION_MATRIX.json
OPERATOR_HANDOFF_MANIFEST.json
OPERATOR_HANDOFF_MANIFEST.json.sha256
```

The exporter never overwrites an existing output directory. The packet is closed-world and SHA-256 manifested; `verify-export` must PASS before the packet is handed to another operator or archived.

## Operator decision rules

- Do not infer FINAL PASS from source completeness.
- Do not infer production acceptance from CI PASS alone.
- Do not infer closure readiness from deployment success alone.
- Do not replace missing evidence with screenshots, chat claims, manual notes, or synthetic fixtures.
- If a gate fails, fix the root cause and rerun that gate plus all downstream gates.
- Historical evidence is append-only; do not edit it to force a result.
- Verify the exact source checkpoint and expected repository before archival or management handoff.

## Current checkpoint note

At Iteration 15.13, the matrix/evaluator/operator handoff contract is source-tooling complete. Real external CI PASS, real production deployment, real operational acceptance, and a real closure artifact remain external runtime evidence requirements.
