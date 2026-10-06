# NADI — External Runtime Target Binding

Iteration 15.18 requires a production-authoritative target binding to prove **both** GitHub repository identity and exact repository materialization. Repository metadata alone is no longer sufficient: the real repository's `main` HEAD must equal the deterministic commit created from the frozen source checkpoint.

## Required authority chain

```text
Frozen checkpoint
  → repository_materialization.py create
  → exact one-root Git commit + Git bundle
  → push exact commit to real GitHub main
  → capture repository metadata + main ref metadata
  → repository_materialization.py confirm-remote
  → external_runtime_target.py bind-metadata
  → external_runtime_target.py dispatch-readiness
  → GitHub Actions workflow_dispatch
```

None of the preparation steps above is CI PASS or `FINAL PASS`.

## 1. Prepare materialized Git history

Create and independently verify the materialization artifact described in `docs/GITHUB_REPOSITORY_MATERIALIZATION.md`.

After pushing the exact bundle commit to the real repository, capture:

```bash
gh api repos/OWNER/REPOSITORY > github-repository-metadata.json
gh api repos/OWNER/REPOSITORY/git/ref/heads/main > github-main-ref.json
```

Then confirm the remote HEAD:

```bash
python3 scripts/repository_materialization.py confirm-remote \
  --materialization-artifact /secure/materialization/NADI-15.18-GIT-MATERIALIZATION.zip \
  --repository-metadata github-repository-metadata.json \
  --ref-metadata github-main-ref.json \
  --expected-repository OWNER/REPOSITORY \
  --output GITHUB_REPOSITORY_MATERIALIZATION_CONFIRMATION.json
```

## 2. Create the production-authoritative target binding

```bash
python3 scripts/external_runtime_target.py bind-metadata \
  --checkpoint-zip NADI-LSP-MIGAS-PROGRESS-ITERATION-15.18-YYYYMMDD.zip \
  --metadata github-repository-metadata.json \
  --materialization-confirmation GITHUB_REPOSITORY_MATERIALIZATION_CONFIRMATION.json \
  --release-version 1.0.0 \
  --confirm-api-metadata \
  --output EXTERNAL_RUNTIME_TARGET_BINDING.json
```

Verify it:

```bash
python3 scripts/external_runtime_target.py verify \
  --binding EXTERNAL_RUNTIME_TARGET_BINDING.json \
  --checkpoint-zip NADI-LSP-MIGAS-PROGRESS-ITERATION-15.18-YYYYMMDD.zip
```

The legacy `bind` subcommand remains diagnostic/non-authorizing.

## 3. Check authoritative workflow-dispatch readiness

```bash
python3 scripts/external_runtime_target.py dispatch-readiness \
  --binding EXTERNAL_RUNTIME_TARGET_BINDING.json \
  --checkpoint-zip NADI-LSP-MIGAS-PROGRESS-ITERATION-15.18-YYYYMMDD.zip \
  --release-version 1.0.0
```

`DISPATCH_READY_NON_PASS` means the repository/ref/commit/checkpoint/version contract is ready to trigger. It is not runtime evidence.

## 4. External CI provenance requirements

A production-authoritative run must match:

```text
provider        github-actions
server_url      https://github.com
repository      metadata-backed owner/repository
repository_id   metadata-backed numeric GitHub repository ID
ref             refs/heads/main
event_name      workflow_dispatch
workflow        NADI Release Gates
workflow_ref    <repo>/.github/workflows/release-gates.yml@refs/heads/main
job             verify
commit_sha      exact materialized_commit_sha
source          exact frozen checkpoint/source-manifest
```

`consume_external_ci.py` verifies the CI provenance against the binding before ingestion or release decision.

## Stop conditions

Stop if repository metadata is missing/synthesized, the repository is archived/disabled, default branch is not `main`, remote HEAD differs from the deterministic materialized commit, source/checkpoint/workflow fingerprints changed, or CI runs from another commit. Any source edit requires a new iteration/refreeze/materialization/binding.
