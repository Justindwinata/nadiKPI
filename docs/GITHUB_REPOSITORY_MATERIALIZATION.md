# NADI — GitHub Repository Materialization & Remote HEAD Confirmation

Iteration 15.18 adds a deterministic Git-history boundary between the frozen source checkpoint and a real GitHub repository. Repository creation/push is an external operator action; this tooling does **not** create a GitHub repository and does **not** claim CI or release PASS.

## 1. Create the materialization package

Use the exact frozen Iteration 15.18 checkpoint and a fixed `SOURCE_DATE_EPOCH`:

```bash
export SOURCE_DATE_EPOCH=1791244800
python3 scripts/repository_materialization.py create \
  --checkpoint-zip /path/to/NADI-LSP-MIGAS-PROGRESS-ITERATION-15.18-YYYYMMDD.zip \
  --output-dir /secure/materialization/NADI-15.18 \
  --output-zip /secure/materialization/NADI-15.18-GIT-MATERIALIZATION.zip
```

The package contains:

- the exact source checkpoint;
- a deterministic one-commit `main` Git bundle;
- expected Git commit and tree SHA-1;
- source/workflow/checkpoint fingerprints;
- closed-world SHA-256 manifest;
- this runbook.

The package is **non-authorizing** by itself.

## 2. Materialize the real repository

Create or identify the intended GitHub repository using the owning organization's normal process. Do not copy-edit files through the GitHub UI.

Example using the bundle:

```bash
git clone SOURCE_GIT_BUNDLE.bundle nadi-lsp-migas
cd nadi-lsp-migas
git remote add origin git@github.com:OWNER/REPOSITORY.git
git push -u origin main
```

Do not amend/rebase the root commit. The remote `main` HEAD must equal `expected_commit_sha` from `GITHUB_REPOSITORY_MATERIALIZATION.json`.

## 3. Capture GitHub repository and remote-ref metadata

```bash
gh api repos/OWNER/REPOSITORY > github-repository-metadata.json
gh api repos/OWNER/REPOSITORY/git/ref/heads/main > github-main-ref.json
```

The second response must identify a `commit` object whose SHA equals the expected materialized commit.

## 4. Confirm the materialized remote

```bash
python3 scripts/repository_materialization.py confirm-remote \
  --materialization-artifact /secure/materialization/NADI-15.18-GIT-MATERIALIZATION.zip \
  --repository-metadata github-repository-metadata.json \
  --ref-metadata github-main-ref.json \
  --expected-repository OWNER/REPOSITORY \
  --output GITHUB_REPOSITORY_MATERIALIZATION_CONFIRMATION.json
```

Only this confirmation may be supplied to the production-authoritative target-binding flow.

## 5. Target binding and CI authority

The target binding must include the confirmed materialized commit. During GitHub Actions execution, `GITHUB_SHA` must equal the same commit SHA. A green workflow from another commit is valid diagnostic evidence at most; it is not release-authorizing for the frozen checkpoint.

## Stop conditions

Stop if:

- the remote `main` commit differs from the materialization receipt;
- history contains an amended/rebased replacement commit;
- repository metadata or numeric repository ID does not match the intended repository;
- source/checkpoint/workflow fingerprints differ;
- the package contains credentials, symlinks, submodules, or unmanifested files;
- any source change occurs after freeze.
