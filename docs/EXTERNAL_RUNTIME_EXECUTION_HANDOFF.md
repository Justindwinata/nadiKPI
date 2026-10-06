# NADI — Final Source Freeze & External Runtime Execution Handoff

This runbook is the authoritative bridge from the final source-frozen NADI checkpoint to a real external runtime execution. It does **not** convert source completeness into `FINAL PASS`.

## 1. Source freeze rule

Iteration 15.15 established the first formal source freeze. Iteration 15.18 re-freezes release-control source after deterministic Git materialization and commit-bound target authority were added.

After an external runtime handoff is created:

- do not edit the checkpoint;
- do not patch the source inside the handoff ZIP;
- do not replace the workflow, gate scripts, lockfiles, or release contracts;
- any required source change creates a **new iteration, regenerated audited source manifest, new checkpoint ZIP, and new handoff**.

Verify the source checkpoint before handoff:

```bash
python3 scripts/source_freeze.py verify-source --repo-root .
```

## 2. Create the external runtime handoff

Use the exact frozen progress checkpoint ZIP and the real GitHub repository identity:

```bash
python3 scripts/source_freeze.py create-handoff \
  --checkpoint-zip /path/to/NADI-LSP-MIGAS-PROGRESS-ITERATION-15.18-YYYYMMDD.zip \
  --expected-repository owner/repository \
  --target-binding EXTERNAL_RUNTIME_TARGET_BINDING.json \
  --output-dir /secure/handoff/NADI-15.18 \
  --output-zip /secure/handoff/NADI-15.18-EXTERNAL-RUNTIME-HANDOFF.zip
```

Optionally bind a semantic release version in advance with `--release-version 1.0.0`. If omitted, the operator must supply a semantic version when triggering `workflow_dispatch`.

The handoff contains the exact checkpoint ZIP, source-freeze receipt, runtime environment contract, release-closure matrix, freeze policy, this runbook, and a closed-world SHA-256 manifest. It contains no runtime credentials.

Verify independently:

```bash
python3 scripts/source_freeze.py verify-handoff \
  --artifact /secure/handoff/NADI-15.18-EXTERNAL-RUNTIME-HANDOFF.zip \
  --expected-repository owner/repository
```

## 3. Execute only through authoritative GitHub Actions

1. Use the deterministic Git materialization bundle; do not manually recreate repository history.
2. Confirm GitHub `refs/heads/main` equals the bound `materialized_commit_sha`.
3. Verify the target binding and run `external_runtime_target.py dispatch-readiness`.
4. Trigger **NADI Release Gates** using `workflow_dispatch` on `main`.
5. Supply the exact semantic `release_version`.
6. Do not amend/rebase/edit source between remote confirmation and workflow execution.

The workflow itself provisions PHP 8.4, required extensions, Composer 2, Node/npm, MySQL 8.4, Playwright Chromium, ephemeral verification credentials, and runs `scripts/final-gate.sh`.

## 4. Expected external evidence

A successful run must produce the unified artifact:

```text
nadi-ci-release-evidence-<run-id>-<run-attempt>
```

The source checkpoint still does **not** become FINAL merely because GitHub shows a green job. Download the artifact and consume it through the existing source-bound path:

```bash
python3 scripts/consume_external_ci.py \
  --artifact /path/to/nadi-ci-release-evidence-<run-id>-<run-attempt>.zip \
  --expected-repository owner/repository \
  --target-binding EXTERNAL_RUNTIME_TARGET_BINDING.json \
  --ingestion-root artifacts/ingested-ci \
  --final-output-dir dist-final
```

Only a source-matched `workflow_dispatch` PASS on `main`, with semantic release version and expected repository identity, can produce the authoritative FINAL release decision.

## 5. Downstream sequence

After real external CI authorization, continue the already frozen path without inventing new source logic:

```text
External CI PASS
  → consume_external_ci.py
  → production_deployment_intake.py
  → deploy-production.sh
  → post_deploy_verify.sh
  → production_operational_acceptance.py
  → release_closure_handoff.py
  → release_closure_matrix.py
  → release_archive.py
```

The G01–G08 closure matrix remains unchanged. Archive/recovery controls remain post-G08 custody controls.

## 6. Stop conditions

Stop and refuse promotion if any of these occur:

- source manifest or freeze-policy verification fails;
- checkpoint SHA-256 differs from the handoff receipt;
- repository identity differs;
- workflow event/ref is not the authorized combination;
- release version is missing/invalid;
- CI evidence does not verify with PASS required;
- package hash differs at any custody boundary;
- production deployment or post-deploy evidence fails;
- any operator attempts to manually rename/repackage an RC as FINAL.

A new source edit always invalidates the old external execution handoff.

## Iteration 15.18 repository materialization and target binding requirement

Before dispatching or consuming a production-authoritative run, create the deterministic repository materialization package, push its exact root commit to `main`, confirm the remote Git ref through GitHub REST metadata, and only then create `EXTERNAL_RUNTIME_TARGET_BINDING.json`. The binding records the materialized commit SHA, and CI `GITHUB_SHA` must match it. Repository metadata without remote materialization confirmation is non-authorizing.
