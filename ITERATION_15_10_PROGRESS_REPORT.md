# NADI — ITERATION 15.10 PROGRESS REPORT

**Iteration:** 15.10 — Production Deployment Artifact Intake & Post-Deploy Verification Contract  
**Date:** 5 October 2026  
**Status:** SOURCE/TOOLING CHECKPOINT COMPLETE — REAL EXTERNAL CI PASS AND REAL PRODUCTION DEPLOYMENT STILL PENDING

## Objective

Close the boundary between an authorized FINAL output from Iteration 15.9 and a production host. A valid build/release decision must not be deployable by merely copying/extracting a ZIP; deployment must preserve artifact provenance, release identity, closed-world integrity, and target-environment acceptance evidence.

This iteration does **not** fabricate the missing real external CI PASS, production deployment, or post-deploy PASS.

## Changes

### 1. Production deployment envelope

Added `scripts/production_deployment_intake.py`.

The tool accepts only the closed-world FINAL output directory produced by `scripts/consume_external_ci.py` and requires:

- `FINAL_RELEASE_DECISION.json` with `FINAL_PASS`;
- `EXTERNAL_CI_CONSUMPTION.json` with `FINAL_PASS`;
- exact source-manifest binding to the current checkpoint;
- explicit expected GitHub repository;
- GitHub Actions `workflow_dispatch` on `refs/heads/main`;
- explicit semantic version;
- exact package SHA-256/size/checksum agreement;
- no extra FINAL-output files/directories.

The resulting deployment envelope contains:

```text
DEPLOYMENT_INTAKE.json
custody/
  EXTERNAL_CI_CONSUMPTION.json
  FINAL_RELEASE_DECISION.json
  NADI-LSP-MIGAS-FINAL-<version>.zip
  NADI-LSP-MIGAS-FINAL-<version>.zip.sha256
release/
  <safe extracted closed-world release tree>
```

ZIP extraction rejects path traversal, absolute paths, duplicates, symlinks, excessive members, and oversized uncompressed content. Every extracted file is rechecked against `RELEASE_MANIFEST.json` before the envelope is authorized.

### 2. Production-host intake verifier

Added `scripts/verify_deployment_intake.php` so the application host does not require Python for provenance verification.

Before dependency installation or database work, it verifies:

- deployment intake schema/status;
- expected repository + CI provenance;
- `workflow_dispatch` / `main` provenance;
- exact current release-root binding;
- release-manifest SHA/version;
- four-file custody set and hashes/sizes;
- FINAL release decision and external-CI consumption receipts;
- custodied FINAL ZIP hash/size.

### 3. Deployment orchestration hardening

`scripts/deploy-production.sh` now has one mandatory 11-phase chain:

```text
1  authorized deployment intake
2  release closed-world integrity
3  runtime/MySQL preflight
4  production Composer install
5  pre-maintenance release validation
6  maintenance mode
7  database migration
8  cache refresh
9  final production readiness
10 application online
11 post-deploy verification
```

Required deployment inputs now include:

```text
NADI_DEPLOYMENT_INTAKE_RECEIPT
NADI_EXPECTED_GITHUB_REPOSITORY
NADI_POST_DEPLOY_BASE_URL
```

If post-deploy verification fails after the release comes online, NADI is returned to maintenance mode. No automatic `migrate:rollback` is performed.

### 4. Post-deploy verification evidence

Added `scripts/post_deploy_verify.sh`.

A production deployment is accepted only after:

- deployment-intake receipt verification;
- `php artisan nadi:release-check --production` PASS;
- scheduler contains `nadi:monitor-risks`;
- `/up` returns 200;
- `/api/health/ready` returns 200;
- SPA shell returns 200;
- HSTS is present;
- CSP is present;
- `X-Content-Type-Options: nosniff` is present.

On success it writes `POST_DEPLOY_VERIFICATION.json`, binding the evidence to the exact deployment-intake receipt and `RELEASE_MANIFEST.json`.

## Controlled regression

These tests exercise the deployment mechanism only. They are **not** real production evidence.

```text
valid FINAL directory → deployment envelope     PASS
production-host intake verifier                 PASS
wrong expected repository                       REJECTED
extra FINAL-directory file                      REJECTED
custody decision tamper                         REJECTED
deploy script with tampered custody              STOPPED BEFORE COMPOSER
mock post-deploy complete readiness/security    PASS (mechanism fixture only)
mock post-deploy missing HSTS                    REJECTED
failed post-deploy evidence file                NOT CREATED
```

## Runtime truth

The release state remains downstream of Iteration 15.9. This room still has no real external CI PASS artifact and no real production host deployment. Therefore:

```text
SOURCE COMPLETE
RELEASE CANDIDATE
REAL EXTERNAL CI PASS      NOT AVAILABLE
PRODUCTION DEPLOYMENT      NOT EXECUTED
POST-DEPLOY ACCEPTANCE     NOT EXECUTED
FINAL PASS                 NOT AUTHORIZED
```

## Rollback boundary

A failed post-deploy contract does not trigger automatic schema rollback. The release is returned to maintenance mode and operators must evaluate forward-fix versus verified backup/release restore according to `docs/BACKUP_AND_RESTORE.md` and `docs/DEPLOYMENT_AND_RELEASE.md`.

## Next checkpoint

**Iteration 15.11 — Production Deployment Evidence Ingestion & Operational Acceptance Decision**

The next checkpoint may bind real deployment-intake and post-deploy evidence into a production acceptance decision, but it must remain fail-closed and must not fabricate a deployment that did not occur.

## Freeze inventory

```text
PHP source files                 159
PHP syntax errors                0
Migration files                  33
Schema::create declarations      41
Models                           34
Test methods discovered          123 (inventory only)
Laravel API routes parsed        79
Frontend API contracts parsed    78
Missing frontend contracts       0
Audited source manifest          243 entries
```

Workflow YAML, release shell syntax, Python compilation, frontend/backend contract audit, deployment-intake regression, and post-deploy contract regression all PASS at source/tooling level. This inventory does not claim the runtime gates blocked by the current environment.
