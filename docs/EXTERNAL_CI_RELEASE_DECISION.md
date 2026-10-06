# NADI — External CI Runtime Execution & Final Release Decision

This document defines the only supported path from a real external CI PASS to `FINAL PASS`.

## Production authorization boundary

A source/tooling PASS, local synthetic fixture, ordinary push run, or pull-request run is **not** sufficient to authorize a production release.

Production release authorization requires all of the following:

1. the authoritative `scripts/final-gate.sh` returns PASS;
2. the evidence is produced by GitHub Actions;
3. the event is an explicit `workflow_dispatch`;
4. the workflow runs from `refs/heads/main`;
5. a non-placeholder semantic release version is supplied (for example `1.0.0`);
6. the CI evidence bundle verifies with `--require-pass` against the exact source checkpoint;
7. the evidence is ingested append-only;
8. the expected GitHub repository identity is supplied when making the release decision;
9. the exact gate-produced ZIP passes extracted closed-world `RELEASE_MANIFEST.json` verification;
10. the final named ZIP is a byte-for-byte copy of the gate-produced ZIP, never a manually repackaged archive.

## External CI execution

Use GitHub Actions **NADI Release Gates → Run workflow** on `main` and supply an explicit version such as:

```text
1.0.0
```

Push and pull-request executions continue to use `ci-gate` and remain diagnostic/release-candidate evidence only.

## Download and verify the evidence artifact

Download the artifact named:

```text
nadi-ci-release-evidence-<run-id>-<run-attempt>
```

From the exact source checkpoint tested by CI:

```bash
python3 scripts/ci_evidence.py verify \
  --bundle-dir /path/to/nadi-ci-release-evidence \
  --require-pass
```

## Ingest append-only

```bash
python3 scripts/ci_evidence.py ingest \
  --bundle-dir /path/to/nadi-ci-release-evidence \
  --destination artifacts/ingested-ci/<run-id>-<attempt> \
  --require-pass
```

The receipt is release-authorizable only for a PASS `workflow_dispatch` run on `main` with a non-`ci-gate` package.

## Make the final decision

The repository identity must be confirmed explicitly:

```bash
python3 scripts/release_decision.py \
  --ingested-evidence artifacts/ingested-ci/<run-id>-<attempt> \
  --expected-repository owner/repository \
  --output-dir dist-final
```

A successful decision produces:

```text
dist-final/
  NADI-LSP-MIGAS-FINAL-<version>.zip
  NADI-LSP-MIGAS-FINAL-<version>.zip.sha256
  FINAL_RELEASE_DECISION.json
```

The final ZIP is copied byte-for-byte from the CI gate package. No repackaging occurs after CI. `FINAL_RELEASE_DECISION.json` binds the final file to the source manifest, CI bundle, ingestion receipt, repository/run provenance, and package SHA-256.

If any requirement fails, the script exits non-zero and must not be bypassed by renaming a checkpoint or CI package manually.

## Iteration 15.9 — one-command artifact consumption

For a downloaded GitHub Actions artifact, prefer the fail-closed consumer instead of manually chaining verify, ingest, and decision commands:

```bash
python3 scripts/consume_external_ci.py \
  --artifact /path/to/nadi-ci-release-evidence-<run-id>-<attempt>.zip \
  --expected-repository owner/repository \
  --target-binding EXTERNAL_RUNTIME_TARGET_BINDING.json \
  --ingestion-root artifacts/ingested-ci \
  --final-output-dir dist-final
```

The consumer also accepts an already-extracted evidence bundle directory.

It performs, in order:

1. safe ZIP extraction when the input is an archive;
2. rejection of path traversal, duplicate members, symlinks, oversized archive members, and files outside the discovered evidence bundle;
3. exact source-bound CI evidence verification with PASS required;
4. production-authorization policy evaluation;
5. explicit expected-repository identity matching;
6. append-only ingestion under `<run-id>-<run-attempt>`;
7. final release decision in a staging directory;
8. byte/hash verification of the final release package;
9. atomic publication of the final output directory only after all previous steps pass.

Successful consumption adds:

```text
EXTERNAL_CI_CONSUMPTION.json
```

beside `FINAL_RELEASE_DECISION.json`, the byte-identical FINAL ZIP, and its checksum. The receipt binds the downloaded artifact identity, source manifest, CI evidence bundle, ingestion receipt, release decision, final package hash, repository identity, and GitHub run provenance.

If the input is a FAIL bundle, an ordinary push/PR PASS, a source-mismatched bundle, a bundle from another repository, or a tampered/wrapped artifact, the consumer exits non-zero and does not publish `dist-final`.
