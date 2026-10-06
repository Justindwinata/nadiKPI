# NADI — Release Closure & Final Acceptance Handoff Runbook

**Applies to:** NADI LSP Migas production release closure  
**Authority chain:** authoritative CI PASS → external artifact consumption → FINAL release decision → deployment intake → production deployment → post-deploy verification → operational acceptance → release-closure handoff

## 1. Purpose

This runbook defines the only supported evidence path for closing a production release. It does not create runtime PASS evidence and it must not be used to promote synthetic fixtures, local demo runs, pull-request checks, or manually assembled ZIP files.

A release-closure handoff is allowed only when all of the following already exist for the **same source checkpoint, semantic version, repository, and GitHub Actions run provenance**:

1. an external CI bundle authorized by the repository release policy;
2. a `FINAL_RELEASE_DECISION.json` with `FINAL_PASS`;
3. an `EXTERNAL_CI_CONSUMPTION.json` with `FINAL_PASS`;
4. a production `DEPLOYMENT_INTAKE.json` with `AUTHORIZED_FOR_DEPLOYMENT`;
5. a closed-world post-deploy evidence bundle with PASS status;
6. a `PRODUCTION_OPERATIONAL_ACCEPTANCE.json` with `ACCEPTED`.

The closure export is an audit/handoff artifact. It is not a replacement for any gate above.

## 2. Required variables

Set explicit values on the trusted release/operations host:

```bash
export NADI_EXPECTED_GITHUB_REPOSITORY="<owner>/<repo>"
export NADI_RELEASE_VERSION="<semver>"
```

Never put credentials into this runbook, checked-in environment files, or release evidence.

## 3. Authoritative external CI

Run the release workflow through GitHub Actions `workflow_dispatch` on `main` using an explicit semantic version. The workflow must finish through `scripts/final-gate.sh`.

Generic push/PR CI PASS is diagnostic evidence only and is **not production release authorization**.

Download the closed-world CI artifact produced by the successful workflow.

## 4. Consume the external CI artifact

```bash
python3 scripts/consume_external_ci.py \
  --repo-root . \
  --artifact /secure/inbox/<github-actions-artifact>.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --target-binding "$NADI_EXTERNAL_RUNTIME_TARGET_BINDING" \
  --ingestion-root artifacts/ingested-ci \
  --final-output-dir dist-final
```

Expected final directory:

```text
dist-final/
  FINAL_RELEASE_DECISION.json
  EXTERNAL_CI_CONSUMPTION.json
  NADI-LSP-MIGAS-FINAL-<version>.zip
  NADI-LSP-MIGAS-FINAL-<version>.zip.sha256
```

Do not rename or repack the FINAL ZIP.

## 5. Create the production deployment envelope

```bash
python3 scripts/production_deployment_intake.py \
  --repo-root . \
  --final-dir dist-final \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --output-dir artifacts/deployment-intake/$NADI_RELEASE_VERSION
```

The resulting envelope is closed-world and contains the original FINAL custody files plus a safely extracted `release/` tree.

## 6. Deploy from the authorized envelope only

On the production host, point deployment to the envelope receipt and release tree. Use production secrets from the host secret manager/environment, not from the evidence bundle.

Required deployment controls include:

- `APP_ENV=production`;
- `APP_DEBUG=false`;
- valid production `APP_KEY`;
- actual MySQL 8.x;
- database-backed session/cache/queue;
- HTTPS production URL;
- scheduler enabled;
- backup/restore readiness;
- demo mode and demo seeding disabled.

The deployment script verifies the deployment intake **before Composer or database work** and remains fail-closed after maintenance begins.

## 7. Produce production post-deploy evidence

`scripts/post_deploy_verify.sh` must produce a closed-world evidence bundle containing:

```text
POST_DEPLOY_EVIDENCE_MANIFEST.json
POST_DEPLOY_VERIFICATION.json
intake.log
release-check.log
schedule.log
up.headers
up.status
ready.headers
ready.status
spa.headers
spa.status
```

The bundle must show HTTP 200 for liveness/readiness/SPA, required security headers, scheduler presence, successful production release-check, and exact deployment-intake/release provenance.

## 8. Ingest and authorize operational acceptance

Back on the trusted source/release host:

```bash
python3 scripts/production_operational_acceptance.py \
  --repo-root . \
  --deployment-envelope artifacts/deployment-intake/$NADI_RELEASE_VERSION \
  --post-deploy-evidence /secure/inbox/<post-deploy-evidence>.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --ingestion-root artifacts/production-acceptance
```

The command writes append-only accepted evidence and `PRODUCTION_OPERATIONAL_ACCEPTANCE.json` only after independent verification succeeds.

## 9. Export the release-closure handoff

Identify the accepted operational evidence directory produced by step 8, then run:

```bash
python3 scripts/release_closure_handoff.py export \
  --repo-root . \
  --deployment-envelope artifacts/deployment-intake/$NADI_RELEASE_VERSION \
  --operational-acceptance-dir artifacts/production-acceptance/<accepted-run> \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --output-dir artifacts/release-closure/$NADI_RELEASE_VERSION \
  --zip-output artifacts/release-closure/NADI-RELEASE-CLOSURE-$NADI_RELEASE_VERSION.zip
```

The export is self-contained. It carries the authorized deployment envelope and accepted post-deploy evidence together with:

```text
RELEASE_CLOSURE.json
RELEASE_CLOSURE_MANIFEST.json
RELEASE_CLOSURE_MANIFEST.json.sha256
```

The exporter rejects runtime `.env` files, private-key material, and recognized credential assignments. It also re-extracts the custodied FINAL ZIP and proves its contents match the deployment release tree.

## 10. Verify the closure handoff independently

Before handing the evidence to operations, audit, management, or long-term retention:

```bash
python3 scripts/release_closure_handoff.py verify \
  --repo-root . \
  --artifact artifacts/release-closure/NADI-RELEASE-CLOSURE-$NADI_RELEASE_VERSION.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY"
```

Expected result:

```text
PASS release closure handoff verification
```

Verification is fail-closed for:

- source-checkpoint mismatch;
- repository mismatch;
- non-authorized deployment intake;
- non-accepted operational decision;
- post-deploy evidence tampering;
- unexpected files;
- symlinks or ZIP path traversal;
- FINAL ZIP/release-tree mismatch;
- secret material in the exported evidence.


## 11. Operator verification matrix

After the closure bundle has been independently verified, generate the operator matrix snapshot from the same source checkpoint:

```bash
python3 scripts/release_closure_matrix.py status \
  --repo-root . \
  --artifact artifacts/release-closure/NADI-RELEASE-CLOSURE-$NADI_RELEASE_VERSION.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY"
```

For archival/management handoff, export the closed-world operator packet:

```bash
python3 scripts/release_closure_matrix.py export \
  --repo-root . \
  --artifact artifacts/release-closure/NADI-RELEASE-CLOSURE-$NADI_RELEASE_VERSION.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --output-dir artifacts/operator-handoff/$NADI_RELEASE_VERSION

python3 scripts/release_closure_matrix.py verify-export \
  --repo-root . \
  --input-dir artifacts/operator-handoff/$NADI_RELEASE_VERSION
```

The authoritative matrix is `config/release-closure-verification-matrix.json`. A real closure can be called verified only when **G01–G08 are all PASS**. The operator packet is a decision/index artifact; it does not replace the self-contained release-closure bundle.

## 12. Release-closure decision semantics

Use the following terms consistently:

```text
SOURCE COMPLETE
  Source/business/release tooling is complete.

RELEASE CANDIDATE
  Runtime release gates have not yet produced authoritative external PASS evidence.

FINAL PASS
  Authoritative external CI has completed the mandatory release gates and produced the authorized FINAL release artifact.

PRODUCTION OPERATIONALLY ACCEPTED
  The authorized FINAL artifact has been deployed and accepted using real post-deploy evidence.

RELEASE CLOSURE HANDOFF READY
  A self-contained closure evidence bundle has been exported and independently re-verified.
```

A closure handoff must never be generated from controlled/synthetic fixtures for real release use.

## 13. Failure and recovery

If any step fails:

1. do not alter the evidence to force PASS;
2. preserve the failing evidence and logs;
3. classify source defect vs environment/deployment defect;
4. fix only the root cause;
5. rerun the affected gate and all downstream gates;
6. create a new append-only evidence run rather than overwriting an accepted/failed historical run.

Do not automatically `migrate:rollback` after a partially applied production deployment. Keep the application in maintenance and use the documented controlled recovery/restore process.

## 14. Current checkpoint note

At the Iteration 15.13 source checkpoint, this runbook, closure tooling, and operator verification matrix are implemented and controlled-regression tested. No real external CI PASS, real production deployment, real operational acceptance, or real release-closure bundle is claimed by this repository checkpoint.

## 15. Long-term archive and recovery rehearsal

After G01–G08 PASS and an independently verified closure/operator packet, follow `docs/RELEASE_ARCHIVE_AND_RECOVERY.md`.

```bash
python3 scripts/release_archive.py create \
  --repo-root . \
  --closure-artifact artifacts/release-closure/NADI-RELEASE-CLOSURE-$NADI_RELEASE_VERSION.zip \
  --operator-handoff-dir artifacts/operator-handoff/$NADI_RELEASE_VERSION \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --retention-until "<approved ISO-8601 UTC timestamp>" \
  --output-dir /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION \
  --zip-output /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION.zip
```

Archive retention is post-release custody, not a ninth release gate. The canonical release authorization matrix remains G01–G08.

Before operational closeout, independently verify the archive and perform a recovery rehearsal:

```bash
python3 scripts/release_archive.py verify \
  --repo-root . \
  --artifact /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY"

python3 scripts/release_archive.py rehearse \
  --repo-root . \
  --artifact /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --receipt-output /secure/archive/receipts/RECOVERY_REHEARSAL-$NADI_RELEASE_VERSION.json
```

The recovery rehearsal re-verifies archive custody, release closure, FINAL ZIP/release-tree equivalence, and operator packet provenance. It does not replace the database restore rehearsal.

## 16. Operational release closeout

When the retained archive remains inside its approved retention window and the exact archive has a PASS recovery receipt:

```bash
python3 scripts/release_archive.py closeout \
  --repo-root . \
  --artifact /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --recovery-receipt /secure/archive/receipts/RECOVERY_REHEARSAL-$NADI_RELEASE_VERSION.json \
  --output /secure/archive/receipts/OPERATIONAL_CLOSEOUT-$NADI_RELEASE_VERSION.json
```

`OPERATIONAL_CLOSEOUT_READY` means release evidence custody/recovery has been closed out. It does not create FINAL PASS or replace the real CI/deployment/production evidence already required by G01–G08.
