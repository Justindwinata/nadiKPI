# NADI — Deployment & Release Runbook

Dokumen ini adalah prosedur release engineering untuk deployment production. Jangan menjalankan `migrate:fresh`, `db:wipe`, atau `PrototypeSeeder` pada database perusahaan.

## 1. Runtime minimum

- Linux production host.
- PHP >= 8.4.1 dengan extension runtime: `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xml`, dan `xmlwriter`. Requirement ini mengikuti dependency lock aktual dan release readiness checker.
- Composer 2.
- Node.js yang memenuhi `engines` dependency pada `package-lock.json` untuk build host; hasil build `public/build` kemudian dapat dipindahkan ke application host.
- Actual MySQL 8.x server (MariaDB/protocol-compatible substitutes are not accepted by the production gate).
- HTTPS reverse proxy.
- Cron atau process supervisor untuk Laravel scheduler.

`xmlwriter` wajib tersedia baik pada CI/build host maupun production runtime agar release checker dan tooling Laravel/PHP konsisten.

## 2. Build host / CI gate

NADI memiliki satu authoritative production release pipeline:

```bash
bash scripts/release-gate-doctor.sh
bash scripts/final-gate.sh
```

GitHub Actions menjalankan `scripts/final-gate.sh` yang sama setelah menyediakan MySQL 8, PHP/Composer, Node/npm, Playwright Chromium, dan ephemeral acceptance credentials. `scripts/verify-release.sh` hanya compatibility alias; jangan membuat atau menjalankan daftar manual parsial sebagai pengganti final gate.

Gate membutuhkan database verifikasi yang boleh dihapus. Set `DB_CONNECTION=mysql` dan credential database test/CI/verify, lalu set `NADI_VERIFY_DESTRUCTIVE_DATABASE` sama persis dengan `DB_DATABASE`. Repository tetap menjalankan `assert_verification_database.php` sebelum setiap destructive reset.

Full gate mencakup clean Composer install, clean `npm ci`, real Vite build, actual MySQL 8 assertion, guarded `migrate:fresh`, PHPUnit-on-MySQL, Pint, production readiness/cache validation, isolated HTTP/browser acceptance, acceptance evidence integrity, deterministic double packaging, dan extracted closed-world verification. Detail environment contract dan failure/retry semantics ada di `docs/RELEASE_GATE_EXECUTION.md`.

Build dianggap gagal bila salah satu phase gagal. Jangan memakai `vendor` atau `node_modules` historis/workstation sebagai final evidence, dan jangan mempromosikan package dari partial/manual execution menjadi production release.

`resources` tidak lagi bergantung pada web-font eksternal; build dan UI production tidak membutuhkan Fontshare/Bunny Fonts.

## 3. Production configuration

Mulai dari `.env.production.example` dan pastikan minimal:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<hostname-resmi>
DB_CONNECTION=mysql
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
VITE_DEMO_MODE=false
```

Generate `APP_KEY` di host yang aman:

```bash
php artisan key:generate
```

Jangan menggunakan akun/seeder demo. Administrator pertama dibuat setelah migrasi:

```bash
php artisan nadi:create-admin admin@perusahaan.co.id --name="Administrator NADI"
```

## 4. Release preflight

NADI menyediakan checker yang menghasilkan exit code non-zero jika release belum memenuhi gate:

```bash
php artisan nadi:release-check --production
```

Untuk pipeline:

```bash
php artisan nadi:release-check --production --json
```

Checker memvalidasi PHP/runtime extensions, APP_KEY 32-byte non-placeholder untuk AES-256-CBC, production flags, HTTPS URL, hostname production non-template, MySQL/PDO driver, credential database non-placeholder, database-backed session/cache/queue, encrypted/secure session, writable runtime directories, Vite manifest/assets, database connectivity, dan pending migration.

## 5. Health probes

- `GET /up` — liveness Laravel; tidak membuktikan database/build siap.
- `GET /api/health/ready` — readiness NADI. Mengembalikan HTTP `200` hanya ketika runtime, build, database, dan migration gate siap; jika tidak, HTTP `503`.

Response readiness sengaja hanya mengeluarkan nama/status check, bukan credential atau detail exception database.

## 6. Production deployment intake and deployment

Backup database **sebelum** migrasi. Production deployment tidak boleh dimulai dari ZIP yang disalin manual. Input deployment yang sah adalah direktori FINAL hasil `scripts/consume_external_ci.py` dari real external-CI PASS yang sudah production-authorizable. Bentuk deployment envelope dengan:

```bash
python3 scripts/production_deployment_intake.py \
  --final-dir /secure/inbox/nadi-final \
  --expected-repository owner/repository \
  --output-dir /secure/releases/nadi-<version>-deployment
```

Envelope bersifat closed-world dan berisi `DEPLOYMENT_INTAKE.json`, custody copy dari FINAL ZIP/checksum/decision/CI-consumption receipt, dan `release/` hasil safe extraction. Intake memverifikasi exact source-checkpoint binding, GitHub `workflow_dispatch` pada `main`, expected repository, semantic release version, package SHA/size, closed-world FINAL directory, safe ZIP structure, dan seluruh file terhadap `RELEASE_MANIFEST.json`.

Transfer **seluruh envelope**, bukan hanya folder `release/`, ke production host. Jalankan deployment dari root `release/` dengan sidecar intake receipt dan expected repository yang eksplisit:

```bash
cd /srv/nadi/releases/nadi-<version>-deployment/release
export APP_ENV=production
export NADI_DEPLOYMENT_INTAKE_RECEIPT=../DEPLOYMENT_INTAKE.json
export NADI_EXPECTED_GITHUB_REPOSITORY=owner/repository
export NADI_POST_DEPLOY_BASE_URL=https://nadi.example.co.id
./scripts/deploy-production.sh
```

Deployment sekarang fail-closed dalam 11 phase. Sebelum Composer/database disentuh, script memverifikasi `DEPLOYMENT_INTAKE.json` dan custody hashes, lalu memverifikasi `RELEASE_MANIFEST.json`. Script kemudian menjalankan runtime/MySQL preflight, production Composer install, dan pre-maintenance release validation. Setelah itu baru maintenance mode diaktifkan, migration dijalankan, cache direfresh, dan final release check dieksekusi.

Setelah aplikasi dinaikkan kembali, `scripts/post_deploy_verify.sh` wajib PASS. Contract ini mengulang production release check, memastikan scheduler `nadi:monitor-risks` terdaftar, memeriksa `/up`, `/api/health/ready`, SPA shell, serta HSTS/CSP/`X-Content-Type-Options`. Hasilnya disimpan sebagai `artifacts/post-deploy/POST_DEPLOY_VERIFICATION.json` dan diikat ke deployment-intake receipt + `RELEASE_MANIFEST.json`. Jika post-deploy verification gagal, deployment **tidak diterima** dan script mengembalikan NADI ke maintenance mode. Tidak ada `migrate:rollback` otomatis.

Jika kegagalan terjadi setelah migration/maintenance dimulai, operator harus mempertahankan evidence, menilai forward-fix terlebih dahulu, dan hanya melakukan restore database/release dari backup yang sudah diverifikasi bila memang diperlukan. Jangan menjalankan `php artisan up` sebelum readiness dan post-deploy contract kembali PASS.

Untuk zero-downtime/high-availability deployment, gunakan atomic release directories/symlink sesuai infrastruktur organisasi dan migration yang kompatibel sebelum traffic switch, tetapi provenance/intake/post-deploy contract tetap wajib dipertahankan.

## 7. Scheduler & monitoring

Aktifkan salah satu metode berikut, jangan keduanya:

```cron
* * * * * cd /var/www/nadi && php artisan schedule:run >> /dev/null 2>&1
```

atau supervisor:

```bash
php artisan schedule:work
```

Verifikasi `nadi:monitor-risks` tampil pada:

```bash
php artisan schedule:list
```

Untuk SSE, reverse proxy harus menonaktifkan buffering pada `/api/notifications/stream` dan memiliki timeout lebih panjang dari `NADI_SSE_STREAM_SECONDS`.

## 8. Post-deploy acceptance

1. `php artisan nadi:release-check --production` = PASS.
2. `/up` = HTTP 200.
3. `/api/health/ready` = HTTP 200.
4. Login administrator berhasil dan tidak menampilkan akun demo.
5. User dipaksa mengganti temporary password bila applicable.
6. Dashboard, Sertifikasi, Finance, IT, Governance, Integration, Decision Center, Notification Center, dan Reporting dapat dibuka sesuai permission.
7. Scheduler terlihat aktif dan risk sync berjalan.
8. Buat satu backup pasca-deployment dan lakukan restore rehearsal pada database non-production.

## 9. Rollback

Rollback source/build harus menggunakan release artifact sebelumnya. **Jangan** menjalankan `migrate:rollback` otomatis pada production tanpa review migration dan backup karena rollback schema dapat membuang kolom/tabel yang berisi data baru.

Jika migration menyebabkan masalah, hentikan traffic/writes, simpan evidence/log, evaluasi forward-fix terlebih dahulu. Restore database hanya dari backup yang checksum-nya telah diverifikasi dan dilakukan sesuai prosedur `BACKUP_AND_RESTORE.md`.

## Exact production database engine gate (Iteration 14.9)

The production database target is **MySQL 8.x**, not merely a server that speaks the MySQL protocol. Release CI and production readiness inspect `SELECT VERSION()` and reject MariaDB or other compatible engines even when their numeric version is greater than 8. This avoids false-positive release validation against semantics that were not the declared production target.

## Production evidence custody (Iteration 15.10)

Simpan minimal tiga evidence set untuk setiap release production:

1. external CI evidence/ingestion + `FINAL_RELEASE_DECISION.json`;
2. `DEPLOYMENT_INTAKE.json` beserta `custody/` envelope;
3. `POST_DEPLOY_VERIFICATION.json` dari host production.

Ketiganya harus menunjuk repository, CI run, semantic version, source manifest, release package, dan release manifest yang sama. `FINAL PASS` pada build/release **tidak otomatis berarti deployment accepted**; production deployment acceptance membutuhkan post-deploy verification PASS pada environment target.
