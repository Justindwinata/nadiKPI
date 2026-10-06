# NADI — Backup & Restore Procedure

Prosedur ini berlaku untuk production MySQL. Sesuaikan credential delivery dengan secret manager organisasi. Jangan menaruh password database pada shell history atau repository.

## Backup sebelum deployment/migration

Buat file client credential sementara di lokasi hanya-baca owner (`chmod 600`), misalnya `/run/secrets/nadi-mysql.cnf`:

```ini
[client]
host=127.0.0.1
port=3306
user=nadi_backup
password=REDACTED
```

Gunakan akun backup read-only yang memiliki privilege yang diperlukan untuk dump, terpisah dari akun aplikasi bila kebijakan perusahaan memungkinkan.

```bash
umask 077
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="nadi_${STAMP}.sql"

mysqldump \
  --defaults-extra-file=/run/secrets/nadi-mysql.cnf \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --default-character-set=utf8mb4 \
  nadi_lsp_migas > "$OUT"

gzip -9 "$OUT"
sha256sum "${OUT}.gz" > "${OUT}.gz.sha256"
```

Simpan `.sql.gz` dan `.sha256` pada storage backup terenkripsi dengan retention sesuai kebijakan perusahaan. Salinan backup tidak boleh berada di web root.

## Verifikasi backup

```bash
sha256sum -c nadi_YYYYMMDDTHHMMSSZ.sql.gz.sha256
gzip -t nadi_YYYYMMDDTHHMMSSZ.sql.gz
```

Backup belum dianggap valid hanya karena `mysqldump` exit code 0. Lakukan restore rehearsal berkala ke database terpisah.

## Restore rehearsal

**Jangan restore langsung ke production untuk pengujian.** Buat database rehearsal kosong:

```bash
mysql --defaults-extra-file=/run/secrets/nadi-mysql-admin.cnf \
  -e "CREATE DATABASE nadi_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

gzip -dc nadi_YYYYMMDDTHHMMSSZ.sql.gz | \
  mysql --defaults-extra-file=/run/secrets/nadi-mysql-admin.cnf nadi_restore_test
```

Kemudian arahkan instance NADI non-production ke `nadi_restore_test` dan jalankan:

```bash
php artisan nadi:release-check --production --skip-build
php artisan migrate:status
```

Lakukan smoke test login, KPI/dashboard, invoice, batch sertifikasi, IT incident, audit trail, integration provenance, risk/action, dan report snapshot.

## Emergency production restore

1. Umumkan incident dan hentikan write/traffic aplikasi (`php artisan down`).
2. Ambil backup database rusak saat ini untuk forensik sebelum overwrite.
3. Verifikasi checksum backup target.
4. Review waktu backup vs potensi kehilangan transaksi setelah backup.
5. Restore ke database baru bila memungkinkan, bukan overwrite in-place.
6. Jalankan `php artisan migrate --force` bila release source membutuhkan migration yang lebih baru.
7. Jalankan `php artisan nadi:release-check --production`.
8. Lakukan smoke test dan reconciliation terhadap sumber data upstream sebelum membuka traffic.
9. Catat incident, backup reference, operator, timestamp, dan hasil validasi pada change/incident record organisasi.

## Minimum schedule

Frekuensi final harus mengikuti RPO/RTO LSP Migas. Baseline yang disarankan untuk fase awal adalah backup harian terenkripsi ditambah backup wajib sebelum setiap schema migration, dengan restore rehearsal terjadwal minimal bulanan sampai operasi stabil.

## Release archive vs database backup

`docs/RELEASE_ARCHIVE_AND_RECOVERY.md` covers long-term custody and recovery verification of release/evidence artifacts. It is **not** a database backup. Full operational recovery may require both:

1. a verified release archive / recovery rehearsal; and
2. a verified MySQL backup / restore rehearsal from this document.

Do not treat a successful release-archive rehearsal as proof that production business data can be restored, and do not treat a successful MySQL restore as proof that the exact release/evidence custody chain is intact.
