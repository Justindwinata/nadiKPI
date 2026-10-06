# NADI — Release Archive Retention, Recovery Verification & Operational Closeout

## Purpose

This procedure starts **after** the authoritative G01–G08 release-closure matrix has passed for a real release. It does not add a ninth release authorization gate and it does not create CI/runtime/production PASS evidence.

Its purpose is long-term custody: retain the verified closure artifact and operator packet, prove they can still be recovered and independently verified, and emit an operational closeout receipt.

## Retention authority

The repository deliberately does **not** hard-code an official LSP Migas retention duration. The operator must provide `retention_until_utc` according to the approved organizational records/retention policy.

Never shorten or extend a retention period merely to make tooling pass. Storage encryption, immutability/WORM controls, geographic replication, legal hold, and deletion/disposition remain infrastructure/organizational controls outside this repository.

## 1. Preconditions

Required for the same source checkpoint, repository and semantic release version:

- independently verified release-closure ZIP;
- verified operator handoff packet with G01–G08 PASS;
- exact repository source checkpoint used to create those artifacts.

## 2. Create retained archive

```bash
python3 scripts/release_archive.py create \
  --repo-root . \
  --closure-artifact /secure/closure/NADI-RELEASE-CLOSURE-$NADI_RELEASE_VERSION.zip \
  --operator-handoff-dir /secure/operator-handoff/$NADI_RELEASE_VERSION \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --retention-until "<approved ISO-8601 UTC timestamp>" \
  --output-dir /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION \
  --zip-output /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION.zip
```

The archive contains only:

```text
RELEASE_ARCHIVE.json
RELEASE_ARCHIVE_MANIFEST.json
RELEASE_ARCHIVE_MANIFEST.json.sha256
RELEASE_ARCHIVE_POLICY.json
closure/<verified closure ZIP>
operator/<closed-world operator packet>
source/AUDITED_SOURCE_RC_MANIFEST.sha256
source/release-closure-verification-matrix.json
```

Creation is non-overwriting and secret-leak guarded.

## 3. Independent archive verification

```bash
python3 scripts/release_archive.py verify \
  --repo-root . \
  --artifact /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY"
```

Verification checks closed-world hashes, source/matrix/policy binding, retention metadata, closure deep verification, FINAL ZIP recovery/release-tree equivalence, and operator packet provenance.

## 4. Recovery rehearsal

```bash
python3 scripts/release_archive.py rehearse \
  --repo-root . \
  --artifact /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --receipt-output /secure/archive/receipts/RECOVERY_REHEARSAL-$NADI_RELEASE_VERSION.json
```

This rehearsal proves that the retained archive can recover and re-verify the release/evidence chain. It **does not replace** the MySQL restore rehearsal in `docs/BACKUP_AND_RESTORE.md`.

## 5. Operational closeout

```bash
python3 scripts/release_archive.py closeout \
  --repo-root . \
  --artifact /secure/archive/NADI-ARCHIVE-$NADI_RELEASE_VERSION.zip \
  --expected-repository "$NADI_EXPECTED_GITHUB_REPOSITORY" \
  --recovery-receipt /secure/archive/receipts/RECOVERY_REHEARSAL-$NADI_RELEASE_VERSION.json \
  --output /secure/archive/receipts/OPERATIONAL_CLOSEOUT-$NADI_RELEASE_VERSION.json
```

Closeout requires:

- archive verification PASS;
- recovery rehearsal PASS;
- matching source/repository/version provenance;
- active retention window.

`OPERATIONAL_CLOSEOUT_READY` means post-release evidence custody/recovery is closed out. It does not create or replace `FINAL PASS`, production deployment acceptance, or G01–G08 evidence.

## 6. Periodic verification and disposition

While the retention window is active, periodically re-run `verify` and record the storage-system audit record externally. Re-run `rehearse` at the organization-approved recovery-test cadence.

When retention expires, the verifier reports `EXPIRED` but still validates integrity. Disposition/deletion must follow approved organizational policy, legal-hold requirements, and storage controls; this repository intentionally does not auto-delete retained evidence.

## 7. Recovery boundaries

Release-archive recovery and database recovery are different controls:

- this document verifies release/evidence custody;
- `docs/BACKUP_AND_RESTORE.md` verifies MySQL backup/restore;
- a real disaster-recovery exercise should validate both if business continuity requires full service reconstruction.
