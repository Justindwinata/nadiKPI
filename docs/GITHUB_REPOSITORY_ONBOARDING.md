# NADI — GitHub Repository Onboarding

Iteration 15.18 prepares the frozen NADI checkpoint for a real GitHub repository without treating onboarding as release evidence.

## 1. Build the non-authorizing onboarding package

```bash
python3 scripts/github_repository_onboarding.py create \
  --checkpoint-zip /path/to/NADI-LSP-MIGAS-PROGRESS-ITERATION-15.18-YYYYMMDD.zip \
  --release-version 1.0.0 \
  --output-dir /secure/onboarding/NADI-15.18 \
  --output-zip /secure/onboarding/NADI-15.18-GITHUB-ONBOARDING.zip
```

Verify independently:

```bash
python3 scripts/github_repository_onboarding.py verify \
  --artifact /secure/onboarding/NADI-15.18-GITHUB-ONBOARDING.zip
```

The package is closed-world and non-authorizing. It carries the exact checkpoint, target request, materialization policy/runbook, repository metadata requirements, and target policy.

## 2. Create deterministic Git materialization

Follow `docs/GITHUB_REPOSITORY_MATERIALIZATION.md`. The frozen checkpoint becomes exactly one root commit on `main`, exported as a deterministic Git bundle.

Do not initialize an unrelated repository and manually copy/edit files after the fact.

## 3. Provision or identify the real GitHub repository

The production target must use:

```text
server       https://github.com
default      main
ref          refs/heads/main
workflow     .github/workflows/release-gates.yml
workflow name NADI Release Gates
event        workflow_dispatch
job          verify
```

Repository visibility is governed by the owning organization. Do not invent the numeric repository ID.

## 4. Push the exact materialized commit

Clone the materialization bundle and push `main` exactly as documented. Do not amend, squash, rebase, or use a GitHub UI edit. The remote `main` commit must remain byte/object-identical to the materialization receipt.

## 5. Capture repository + remote-ref metadata

```bash
gh api repos/OWNER/REPOSITORY > github-repository-metadata.json
gh api repos/OWNER/REPOSITORY/git/ref/heads/main > github-main-ref.json
```

Confirm remote materialization with `repository_materialization.py confirm-remote`.

## 6. Bind the target and verify dispatch readiness

Create `EXTERNAL_RUNTIME_TARGET_BINDING.json` with `external_runtime_target.py bind-metadata`, providing the materialization confirmation. Then run `dispatch-readiness` with the semantic release version.

Only after those steps may the operator trigger the authoritative `workflow_dispatch` on `main`.

## Stop conditions

Stop if repository identity, numeric ID, remote HEAD, source/checkpoint/workflow hash, or materialization confirmation differ. A source change requires a new iteration, source manifest, checkpoint, materialization package, and target binding.
