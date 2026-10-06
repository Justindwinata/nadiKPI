# NADI — CI Release Evidence Ingestion

This document defines how executable release-gate evidence produced by CI is carried back into a NADI source checkpoint without trusting chat claims, filenames, or an isolated ZIP.

## Authority model

`scripts/final-gate.sh` remains the only production release authority. CI does not create a second release decision path.

After the final gate finishes (PASS or FAIL), `.github/workflows/release-gates.yml` runs:

```bash
python3 scripts/ci_evidence.py bundle \
  --gate-dir artifacts/release-gate \
  --browser-dir artifacts/browser-acceptance \
  --bundle-dir artifacts/ci-evidence

python3 scripts/ci_evidence.py verify \
  --bundle-dir artifacts/ci-evidence
```

The workflow uploads the resulting directory as one artifact named with the GitHub run ID and attempt.

## Bundle contents

`artifacts/ci-evidence/` contains:

```text
CI_EVIDENCE_BUNDLE.json
CI_EVIDENCE_BUNDLE.json.sha256
payload/
  release-gate/
  browser-acceptance/       # when produced
  release/                  # PASS only; exactly one gate ZIP
```

The manifest is closed-world and records SHA-256 + byte size for every payload file. It also binds the evidence to the current source checkpoint through fingerprints of:

- `AUDITED_SOURCE_RC_MANIFEST.sha256`;
- `composer.lock`;
- `package-lock.json`;
- runtime environment contract;
- authoritative final gate;
- acceptance evidence implementation;
- CI evidence implementation;
- release workflow.

On GitHub Actions, repository/commit/run/workflow/job/runner provenance is also recorded. No acceptance password, database password, APP key, or other secret value is serialized by this layer.

## PASS-specific requirements

A CI bundle is a valid PASS evidence bundle only when all of the following are simultaneously true:

1. `final_gate.json` has schema `nadi.final-gate.v1` and `status = pass`;
2. every mandatory runtime gate in the authoritative matrix is present and equals `pass`;
3. runtime contract/environment evidence hashes match `final_gate.json`;
4. browser acceptance evidence passes the repository's own closed-world/source-bound verifier;
5. source manifest hash matches the current checkpoint;
6. exactly one release ZIP is present in the bundle;
7. the ZIP SHA-256 equals the package SHA recorded by the final gate;
8. the CI evidence payload is closed-world and every file hash/size matches.

A failed gate can still be bundled and ingested for diagnosis, but it is always marked `release_authorizable = false`.

## Verify a downloaded CI artifact

From the exact source checkpoint that the CI run tested:

```bash
python3 scripts/ci_evidence.py verify \
  --bundle-dir /path/to/downloaded/nadi-ci-release-evidence \
  --require-pass
```

`--require-pass` must be used before considering the evidence for production release authorization.

The command fails if the bundle belongs to a different source checkpoint, has been modified, contains unexpected files, lacks the release ZIP, has inconsistent final-gate links, or represents a failed gate.

## Ingest verified evidence

Use a new immutable destination per run/attempt:

```bash
python3 scripts/ci_evidence.py ingest \
  --bundle-dir /path/to/downloaded/nadi-ci-release-evidence \
  --destination artifacts/ingested-ci/<run-id>-<attempt> \
  --require-pass
```

The destination receives `CI_EVIDENCE_INGESTION.json` with the ingested bundle hash, source-manifest hash, CI provenance, gate status, and `release_authorizable` decision.

Do not overwrite an existing ingestion directory. A repeated run must use a new destination so evidence history is append-only at the workspace level.

## Failure evidence

If the authoritative final gate fails, CI still attempts to create and upload a source-bound failure bundle. Raw gate/browser evidence is also uploaded as diagnostic fallback. This preserves the failing phase and environment evidence without promoting the run to PASS.

## Important limitation

The evidence-ingestion layer verifies evidence produced elsewhere. It does **not** convert source/tooling regression into runtime PASS and cannot make the current environment satisfy Composer, PHP extensions, MySQL, npm registry, or browser-policy requirements.


## Production authorization policy

A valid PASS evidence bundle is not automatically a production authorization. Ingestion sets `release_authorizable=true` only for GitHub Actions evidence produced by the `NADI Release Gates` workflow through an explicit `workflow_dispatch` on `refs/heads/main` with a semantic non-`ci-gate` package version. Ordinary push/PR PASS runs remain useful verification evidence but cannot authorize FINAL.

After successful ingestion, use `scripts/release_decision.py` and explicitly provide the expected GitHub repository identity. See `docs/EXTERNAL_CI_RELEASE_DECISION.md`.

## External artifact consumer

Iteration 15.9 adds `scripts/consume_external_ci.py` as the preferred production consumption path for a downloaded GitHub Actions artifact. It accepts either the GitHub artifact ZIP or an extracted evidence directory and combines PASS verification, repository/provenance authorization, append-only ingestion, final decision, and atomic final-output publication. Manual `verify` / `ingest` / `release_decision` commands remain available for forensic diagnosis, but do not bypass the consumer's production policy.
