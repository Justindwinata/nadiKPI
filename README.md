# NADI — Monitoring KPI LSP Migas

NADI adalah prototipe sistem keputusan lintas divisi untuk pimpinan, sertifikasi, keuangan, teknologi informasi, serta mutu & kepatuhan. Backend menggunakan Laravel 13, antarmuka menggunakan React, visualisasi menggunakan Chart.js, animasi menggunakan GSAP, dan skema produksi ditujukan untuk MySQL.

Semua angka yang disertakan adalah data sintetis deterministik untuk demonstrasi. Struktur prosesnya didasarkan pada informasi publik LSP Migas dan BNSP, tetapi nilainya bukan performa perusahaan.

## Fitur yang sudah berfungsi

- autentikasi berbasis sesi, login throttling, forced password change, serta RBAC granular per aksi dengan override per pengguna;
- skor KPI gabungan berbobot dengan dukungan indikator semakin tinggi atau semakin rendah;
- histori KPI 12 bulan; seluruh sembilan KPI bawaan dihitung otomatis dari ledger operasional Sertifikasi, Keuangan, dan TI; KPI custom yang belum memiliki sumber sistem tetap dapat menggunakan pengukuran manual;
- **Katalog KPI versioned** untuk target, warning threshold, bobot, owner, periode efektif, aktivasi/nonaktif, dan histori konfigurasi tanpa menulis ulang masa lalu;
- dashboard pimpinan lintas divisi dengan sinyal risiko dan tindak lanjut;
- lifecycle sertifikasi end-to-end: batch, hasil kompeten/belum kompeten, keputusan, jatuh tempo sertifikat otomatis 30 hari, penerbitan sertifikat bertahap, backlog, dan SLA;
- analisis pendapatan, beban, margin, anggaran, dan komposisi biaya;
- analisis layanan TI, insiden, uptime overlap-safe, MTTR, dan ledger audit kualitas data;
- perubahan tahap batch berurutan dengan business invariant, insiden, dan tindak lanjut;
- pencatatan transaksi keuangan;
- import pengukuran KPI custom dari CSV dan unduh template;
- real-data onboarding untuk 11 dataset Sertifikasi/Finance/IT melalui staging, column mapping, validation, idempotency, provenance, dan controlled publish;
- governance register untuk temuan, CAPA, banding sertifikasi, obligation, expiry risk, provenance, rekonsiliasi, dan audit-log viewer;
- audit log untuk perubahan data penting, event autentikasi, administrasi akses, perubahan konfigurasi KPI, serta lifecycle master data;
- master data skema/TUK/asesor/layanan IT dapat diedit, diarsipkan, dan dipulihkan secara non-destructive dengan dependency protection;
- administrasi pengguna: buat akun, aktif/nonaktifkan, reset password sementara, permission override beralasan, dan pemutusan sesi;
- security headers serta template konfigurasi production-safe;
- tampilan responsif untuk desktop, tablet, dan telepon.

## Menjalankan prototipe

Persyaratan lokal mengikuti lockfile release saat ini: **PHP 8.4.1 atau lebih baru**, Composer, Node.js `^20.19.0` atau `>=22.12.0`, npm `>=10`, serta ekstensi PHP `mbstring`, `dom`, `xml`, `xmlwriter`, dan driver PDO database (`pdo_mysql` untuk produksi atau `pdo_sqlite` untuk demo/test). Untuk demo cepat, SQLite dapat digunakan.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

Ubah `DB_CONNECTION=sqlite` pada `.env` untuk demo tanpa MySQL, lalu jalankan:

```bash
php artisan migrate:fresh --seed
npm run build
composer run dev
```

Aplikasi tersedia pada `http://localhost:8000`.

## Konfigurasi MySQL

Buat basis data kosong, kemudian isi `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nadi_lsp_migas
DB_USERNAME=nadi_user
DB_PASSWORD=ganti_dengan_password_kuat
```

Jalankan `php artisan migrate:fresh --seed` hanya pada lingkungan demonstrasi karena perintah tersebut menghapus tabel. Pada data perusahaan gunakan `php artisan migrate --force` dan lakukan backup lebih dulu.

## Akun demonstrasi

Daftar berikut hanya untuk lingkungan prototype. Frontend tidak menyimpan literal credential ini; saat `NADI_DEMO_MODE=true` dan build demo diaktifkan, halaman login mengambil akun melalui endpoint server `/api/demo-access`. Pada production endpoint tersebut dinonaktifkan (`404`) dan `VITE_DEMO_MODE=false`.


Akun di bawah hanya untuk prototype. Build produksi wajib menggunakan `VITE_DEMO_MODE=false` dan **tidak menjalankan `PrototypeSeeder`**.


| Peran | Email | Kata sandi | Akses |
|---|---|---|---|
| Pimpinan | `pimpinan@demo.test` | `dibuat acak oleh helper local demo` | Semua modul termasuk Pusat Keputusan & Management Review |
| Kepala Sertifikasi | `sertifikasi@demo.test` | `dibuat acak oleh helper local demo` | Ikhtisar, Sertifikasi, Pusat Keputusan divisi, Governance, dan Integrasi Data Sertifikasi |
| Kepala Keuangan | `keuangan@demo.test` | `dibuat acak oleh helper local demo` | Ikhtisar, Keuangan, Pusat Keputusan divisi, Governance, dan Integrasi Data Finance |
| Kepala TI | `it@demo.test` | `dibuat acak oleh helper local demo` | Ikhtisar, Teknologi, Pusat Keputusan divisi, Governance, dan Integrasi Data IT |
| Kepala Mutu & Kepatuhan | `mutu@demo.test` | `dibuat acak oleh helper local demo` | Ikhtisar, Governance, Pusat Keputusan lintas domain, Management Review, dan Integrasi Data lintas domain |

## Identity, RBAC, dan deployment produksi

NADI menerapkan permission per aksi, bukan hanya per menu. Role bawaan adalah `director`, `department_head`, `analyst`, dan `viewer`; default permission ditentukan oleh role dan divisi, lalu dapat dioverride per pengguna dengan alasan yang diaudit. Aksi sensitif seperti reversal Finance, penerbitan sertifikat, provenance/reconciliation, dan administrasi user dipisahkan dari hak baca atau input biasa.

Akun baru yang dibuat administrator menggunakan password sementara dan wajib menggantinya sebelum dapat membuka route operasional. Reset password juga memutus sesi lama. Sistem mencegah user menonaktifkan dirinya sendiri dan menjaga agar sekurang-kurangnya satu akun Pimpinan aktif tetap tersedia.

Untuk production, mulai dari `.env.production.example`, gunakan HTTPS, MySQL dengan akun least-privilege, dan set `VITE_DEMO_MODE=false`. Jangan menjalankan seeder prototype. Setelah migrasi, buat administrator pertama melalui CLI:

```bash
php artisan migrate --force
php artisan nadi:create-admin admin@perusahaan.co.id --name="Administrator NADI"
```

Command akan meminta password jika opsi password tidak diberikan. Gunakan password kuat dan simpan melalui kanal rahasia perusahaan. Detail checklist ada pada `docs/SECURITY.md`.

## Real data onboarding

Gunakan halaman **Integrasi Data** untuk memasukkan export data perusahaan. File tidak langsung ditulis ke tabel operasional. NADI membuat batch ber-checksum, menyimpan raw row pada staging, menjalankan mapping/validation, melakukan idempotency check menggunakan external key + row hash, lalu baru mem-publish record yang lolos.

Dataset yang didukung saat ini mencakup master dan transaksi Sertifikasi, Finance, serta IT. Template canonical tersedia per dataset. Header dari sistem lama dapat dipetakan melalui UI dan disimpan menjadi reusable mapping profile. Record ledger yang bersifat immutable tidak dapat dioverwrite oleh import; koreksi tetap dilakukan melalui reversal/void/workflow domain.

Panduan urutan onboarding dan checklist produksi tersedia di `docs/INTEGRATION_ONBOARDING.md`.

## Katalog KPI dan target perusahaan

Gunakan menu **Katalog KPI** untuk mengelola indikator tanpa mengubah source code. Sembilan KPI bawaan tetap system-derived; yang dapat dikonfigurasi adalah target, batas waspada, bobot, owner, periode efektif, dan status aktif. Perubahan dibuat sebagai versi baru sehingga dashboard periode lama tetap membaca kebijakan KPI yang berlaku pada periode tersebut.

KPI custom dapat dibuat untuk kebutuhan perusahaan yang belum memiliki calculation engine operasional. KPI custom menerima measurement manual/CSV, tetapi measurement hanya diterima jika konfigurasi indikator aktif pada periodenya. Setiap perubahan konfigurasi memerlukan alasan dan masuk audit trail.

## Lifecycle master data

Menu **Pusat Data** mendukung tambah, edit, archive, dan restore. Archive bukan delete: histori batch, insiden, provenance, dan audit tetap mempertahankan referensi ke record lama. Sistem menolak archive jika masih ada proses aktif yang bergantung pada master tersebut, dan record terarsip tidak dapat dipilih untuk transaksi baru.

## Import data KPI

Unduh template CSV dari tombol pada dashboard. Kolom wajib adalah:

```csv
kpi_code,period,actual,notes
CUSTOM-KPI,2026-09-01,98,Ganti dengan kode KPI manual yang sudah dikonfigurasi
```

Kode KPI harus terdaftar, nilai harus numerik, dan pengguna hanya dapat mengimpor indikator yang berada dalam hak aksesnya. Seluruh KPI bawaan (`CERT-VOLUME`, `CERT-PASS`, `CERT-SLA`, `FIN-REV`, `FIN-MARGIN`, `FIN-BUDGET`, `IT-UPTIME`, `IT-MTTR`, dan `IT-DATA`) **tidak menerima input manual maupun CSV** karena dihitung otomatis dari ledger operasional masing-masing domain. CSV hanya untuk KPI custom/manual yang sudah dikonfigurasi. Nilai manual pada bulan dan KPI custom yang sama diperbarui secara idempoten.

## Workflow keuangan

Keuangan menggunakan dua lapisan yang tetap terhubung: `financial_records` sebagai ledger utama dan `finance_invoices`/`finance_payments` sebagai sub-ledger piutang. Gunakan **Buat invoice** untuk pendapatan berbasis tagihan; sistem otomatis membuat posting revenue. Gunakan **Catat ledger** untuk beban, anggaran, atau pendapatan non-invoice. Koreksi tidak dilakukan dengan delete: gunakan **Reverse** pada ledger atau pembayaran, dan gunakan **Void invoice** setelah pembayaran aktif direversal.

Dashboard Finance menampilkan revenue, expense, margin, budget variance, pembayaran diterima, open/overdue receivable, aging, dan status rekonsiliasi invoice-to-ledger.

## Workflow teknologi informasi

Insiden TI berjalan `open → investigating → resolved`. Penyelesaian menyimpan waktu selesai, ringkasan resolusi, root cause opsional, dan user penyelesai. Downtime dihitung dengan menggabungkan interval insiden yang tumpang tindih **per layanan** dan memotong interval pada batas periode, sehingga outage yang sama tidak dihitung dua kali. MTTR menggunakan cohort insiden yang selesai pada bulan laporan dan mengukur durasi penuh dari mulai sampai resolved.

Audit kualitas data dicatat pada `data_quality_runs` dengan dataset, source system, jumlah rekam, rekam valid, serta temuan missing/duplikat/freshness. `IT-DATA` adalah rasio tertimbang total rekam valid terhadap total rekam yang diaudit. `IT-UPTIME`, `IT-MTTR`, dan `IT-DATA` semuanya system-derived dan tidak dapat dioverride manual/CSV.


## Workflow governance dan provenance

Halaman **Mutu & Kepatuhan** memusatkan temuan audit, CAPA, banding sertifikasi, expiry watchlist, obligation register, sumber data, import batch, rekonsiliasi, dan audit trail. CAPA membutuhkan evidence untuk diselesaikan; temuan tidak dapat ditutup sebelum seluruh CAPA selesai. Banding selalu terkait ke batch sertifikasi dan keputusan/resolusinya dicatat.

Setiap impor CSV dicatat sebagai `data_import_batches` dengan checksum SHA-256, nama file, jumlah baris diterima/ditolak, errors, serta status rekonsiliasi. Pimpinan/Mutu dapat mendaftarkan hierarchy sumber data dan menyelesaikan exception rekonsiliasi. Field masa berlaku pada skema, TUK, asesor, serta obligation membentuk expiry watchlist 90 hari.

## Struktur penting

```text
app/
  Http/Controllers/      use case API, validasi, dan audit
  Http/Middleware/       autentikasi, forced-password gate, permission, dan security headers
  Models/                entitas dan relasi Eloquent
  Services/              rumus/agregasi KPI dan controlled integration onboarding
database/
  migrations/            skema MySQL/SQLite
  seeders/               dataset prototipe
resources/
  js/app.jsx             SPA React dan visualisasi
  css/custom.css         sistem visual responsif
  views/app.blade.php    shell Vite
docs/
  RESEARCH_LSP_MIGAS.md  riset, KPI, risiko, dan sumber
  ARCHITECTURE.md        arsitektur, ERD, dan aliran data
  INTEGRATION_ONBOARDING.md kontrak onboarding dan idempotency
tests/Feature/           pengujian akses dan persistensi
```

## Verifikasi

```bash
php artisan test
./vendor/bin/pint --test
npm run build
```

Dokumen [analisis LSP Migas](docs/RESEARCH_LSP_MIGAS.md) menjelaskan dasar proses dan rancangan KPI. Dokumen [arsitektur](docs/ARCHITECTURE.md) menjelaskan relasi data dan jalur adaptasi menuju data perusahaan.

## Pusat Keputusan dan Management Review

Menu **Pusat Keputusan** mengubah exception menjadi workflow yang dapat ditindaklanjuti. Risk engine membuat signal dari KPI yang keluar jalur, temuan/CAPA terlambat, piutang jatuh tempo, backlog sertifikat melewati SLA, insiden TI high/critical, serta kewajiban yang mendekati expiry. Signal memakai fingerprint sumber sehingga refresh tidak menghasilkan duplikasi.

Workflow operasional:

```text
Exception operasional
→ Risk Signal
→ Acknowledge
→ Owner / Action Item
→ Escalation bila perlu
→ Resolution + evidence
→ Management Review
→ Decision / Approval / Closure
```

Action yang ditutup wajib mempunyai catatan resolusi dan bukti/referensi. Management Review menyimpan agenda signal, keputusan per item, owner, tenggat, ringkasan rapat, keputusan akhir, approver, dan histori status. Permission `decisions.view`, `decisions.manage`, dan `decisions.review` memisahkan hak melihat, mengeksekusi, serta menyetujui review.

## Monitoring terjadwal dan Notification Center

NADI tidak bergantung pada user membuka Pusat Keputusan untuk menemukan exception. Command `php artisan nadi:monitor-risks` menyinkronkan risk signal, membuat reminder action overdue, menyiapkan reminder Management Review, dan melakukan auto-escalation untuk signal kritis yang belum diakui. Production harus menjalankan Laravel scheduler setiap menit melalui cron atau `schedule:work`.

Notification Center menampilkan inbox personal dengan unread/read/acknowledged state. Delivery browser menggunakan Server-Sent Events (SSE) dan mempunyai fallback refresh bila stream terputus. Acknowledgement notifikasi hanya menandai pesan telah diterima; acknowledgement risk signal tetap dilakukan melalui **Pusat Keputusan**.

Konfigurasi monitoring:

```dotenv
NADI_CRITICAL_ESCALATE_LEVEL1_MINUTES=120
NADI_CRITICAL_ESCALATE_LEVEL2_MINUTES=480
NADI_NOTIFICATION_RETENTION_DAYS=180
NADI_SSE_STREAM_SECONDS=20
```

Panduan production scheduler, SSE/reverse proxy, recipient policy, retention, dan health-check tersedia di `docs/MONITORING_AND_NOTIFICATIONS.md`.

## Reporting & Management Evidence Pack

Menu **Laporan** membuat snapshot immutable dari KPI dan evidence operasional. Tipe report mencakup Executive, Kinerja Divisi, Sertifikasi, Keuangan, IT Reliability, Governance, Risk & Action Register, serta Management Review. Snapshot menyimpan periode, generator, konfigurasi KPI efektif, provenance import, dan SHA-256 yang mengikat payload + source manifest.

Output yang tersedia untuk user dengan `reports.export` adalah printable HTML (dapat Print/Save as PDF dari browser), JSON, CSV, dan **Evidence Pack ZIP**. ZIP berisi manifest, report JSON/CSV, printable report, KPI snapshot, provenance, dan tabel evidence yang relevan. Export selalu berasal dari snapshot tersimpan, bukan menghitung ulang live data saat download.

Panduan lengkap terdapat di `docs/REPORTING_AND_EVIDENCE.md`.

## Release engineering dan deployment production

NADI memiliki **satu** production release gate yang authoritative. Jalankan pada host/CI dengan PHP extension lengkap, Composer, Node/npm, Playwright + Chromium, dan database verifikasi MySQL 8 yang boleh dihapus:

```bash
bash scripts/release-gate-doctor.sh
bash scripts/final-gate.sh
```

`scripts/verify-release.sh` dipertahankan hanya sebagai compatibility alias dan langsung mendelegasikan ke `scripts/final-gate.sh`. Pada macOS, `FINALIZE_AND_RUN_MAC.command --release-gate` juga mendelegasikan ke gate yang sama; mode default `--demo` memakai SQLite/synthetic data dan **tidak pernah** menjadi production evidence. Kontrak bootstrap host ada di `docs/RUNTIME_BOOTSTRAP.md`; orchestration dan failure/retry semantics ada di `docs/RELEASE_GATE_EXECUTION.md`. Jika runner memakai Chromium yang dikelola sistem, `NADI_BROWSER_EXECUTABLE_PATH` dapat menunjuk executable tersebut; hal ini tidak melewati browser acceptance.

Release verification memakai database test/CI/verify yang boleh dihapus. `NADI_VERIFY_DESTRUCTIVE_DATABASE` wajib sama persis dengan `DB_DATABASE`; assertion repository menolak `migrate:fresh` jika database tidak jelas test/CI/verify scoped. Gate authoritative menjalankan clean Composer/npm installs, real Vite build, actual MySQL 8 assertion, guarded migrations, PHPUnit-on-MySQL, Pint, production readiness, HTTP acceptance, browser functional acceptance, source-bound evidence verification, deterministic packaging, dan extracted closed-world verification.

Readiness produksi dapat diperiksa secara eksplisit, tetapi perintah ini sendiri bukan pengganti full final gate:

```bash
php artisan nadi:release-check --production
```

Endpoint operasional dibedakan menjadi:

- `/up` untuk **liveness** proses aplikasi;
- `/api/health/ready` untuk **readiness** dependency/configuration. Endpoint readiness tidak menggunakan session middleware dan akan mengembalikan HTTP `503` secara terstruktur ketika database, extension, atau build artifact belum siap.

Production deployment hanya boleh memakai ZIP yang berasal dari successful authoritative gate. `scripts/package_release.py` tetap fail-closed bila `public/build/manifest.json` tidak tersedia, mengecualikan secret/runtime/dependency/evidence artifacts, lalu menambahkan `RELEASE_MANIFEST.json` dengan SHA-256 setiap file. Deployment dan rollback dijelaskan di `docs/DEPLOYMENT_AND_RELEASE.md`; prosedur backup/restore MySQL berada di `docs/BACKUP_AND_RESTORE.md`.

**Current source release candidate:** business/source hardening dan release-gate/evidence/deployment/closure/operator-handoff tooling sudah ditutup sampai Iteration 15.13. Source RC tetap **bukan final production release** sampai `scripts/final-gate.sh` benar-benar selesai end-to-end pada environment yang memenuhi prerequisites, termasuk clean dependency installs, real Vite build, actual MySQL 8, PHPUnit/Pint, production readiness, HTTP/browser acceptance, evidence integrity, deterministic packaging, dan extracted closed-world verification.

### CI release evidence

Release CI exports one closed-world, source-bound evidence bundle after `scripts/final-gate.sh`. The bundle includes diagnostic evidence on failure and exactly one gate-produced release ZIP only after an end-to-end PASS. Downloaded evidence must be verified against the exact checkpoint with `scripts/ci_evidence.py verify --require-pass` and ingested append-only. Production authorization is stricter than generic PASS evidence: it requires a GitHub Actions `workflow_dispatch` on `main`, an explicit semantic release version, and an external runtime target binding that matches the real GitHub repository name, numeric repository ID, server, workflow and frozen source checkpoint. A downloaded GitHub Actions artifact can be consumed fail-closed with `scripts/consume_external_ci.py`, which performs safe extraction, PASS verification, append-only ingestion, release decision, and atomic final-output publication. See `docs/CI_EVIDENCE_INGESTION.md` and `docs/EXTERNAL_CI_RELEASE_DECISION.md`.

### Production deployment custody

An authorized FINAL directory is still **not** sufficient by itself for deployment. Run `scripts/production_deployment_intake.py` to produce a closed-world deployment envelope that carries `DEPLOYMENT_INTAKE.json`, custody copies of the external-CI decision/consumption evidence and FINAL ZIP/checksum, plus a safely extracted `release/` tree. Production deployment must run from that `release/` directory with `NADI_DEPLOYMENT_INTAKE_RECEIPT`, `NADI_EXPECTED_GITHUB_REPOSITORY`, and `NADI_POST_DEPLOY_BASE_URL` set. `scripts/deploy-production.sh` verifies provenance before Composer/database work and requires `scripts/post_deploy_verify.sh` to PASS before the deployment is accepted. See `docs/DEPLOYMENT_AND_RELEASE.md`.

## Iteration 15.11 — Production Deployment Evidence Ingestion & Operational Acceptance Decision

Iteration 15.11 makes post-deploy acceptance independently re-verifiable. `scripts/post_deploy_verify.sh` now preserves a closed-world evidence bundle instead of deleting the raw logs/header/status evidence after producing `POST_DEPLOY_VERIFICATION.json`. The bundle contains the production intake verification log, production release-check log, scheduler evidence, HTTP status/header evidence for liveness/readiness/SPA, the verification JSON, and `POST_DEPLOY_EVIDENCE_MANIFEST.json` with SHA-256/size binding.

New `scripts/production_operational_acceptance.py` accepts that evidence as a directory or ZIP, safely extracts archives, re-verifies the authorized deployment envelope against the exact source checkpoint, checks CI/repository/version/release-manifest provenance, independently re-checks HTTP 200/security-header/scheduler evidence, ingests the accepted evidence append-only, and only then writes `PRODUCTION_OPERATIONAL_ACCEPTANCE.json` with decision `ACCEPTED`.

Controlled regression validates the mechanism only. No real production deployment or real production post-deploy evidence exists in this room, so operational acceptance and FINAL production PASS remain unclaimed.

## Iteration 15.12 — Production Evidence Export / Release Closure Handoff

After a real deployment has been independently accepted by `scripts/production_operational_acceptance.py`, `scripts/release_closure_handoff.py` can create one self-contained closure evidence package for audit/operations handoff. The exporter requires the authorized deployment envelope and accepted operational-evidence directory from the exact same source checkpoint, repository, semantic release version, and CI provenance.

The closure bundle contains the full deployment envelope and accepted post-deploy evidence, plus `RELEASE_CLOSURE.json`, `RELEASE_CLOSURE_MANIFEST.json`, and its SHA-256 sidecar. Verification re-runs deployment/post-deploy checks, re-extracts the custodied FINAL ZIP and compares it to the deployment release tree, and rejects tampering, extra files, unsafe archives, repository/source mismatch, and recognized secret material.

See `docs/RELEASE_CLOSURE_RUNBOOK.md` for the authoritative CI → FINAL → deployment → post-deploy acceptance → release-closure sequence. Controlled regression proves the mechanism only; no real external CI PASS, real production deployment, operational acceptance, or real closure handoff is claimed in this checkpoint.


## Iteration 15.13 — Release Closure Verification Matrix / Operator Handoff Hardening

Iteration 15.13 adds one canonical eight-gate release-closure matrix (`config/release-closure-verification-matrix.json`) and `scripts/release_closure_matrix.py`. The evaluator re-hashes the current audited source checkpoint, maps every release milestone to one evidence authority/state, and reports `WAITING_EXTERNAL_EVIDENCE` rather than inferring PASS when real external evidence does not exist.

A real independently verified closure artifact is the only path that can produce `RELEASE_CLOSURE_VERIFIED` with G01–G08 all PASS. Operator exports are closed-world and contain `OPERATOR_HANDOFF.json`, a human-readable checklist, the exact matrix copy, an SHA-256 manifest and digest sidecar. Tampering, extra files, overwrite attempts, repository mismatch and synthetic-authority policy drift are fail-closed. The authoritative final gate also validates the matrix/source checkpoint before runtime execution. See `docs/RELEASE_CLOSURE_VERIFICATION_MATRIX.md`.

## Iteration 15.14 — Release Archive Retention / Recovery Verification & Operational Closeout

Iteration 15.14 adds post-G08 release custody tooling without changing the canonical eight-gate release authorization matrix. `scripts/release_archive.py` creates a closed-world long-term archive only from an independently verified release-closure ZIP and a verified G01–G08 operator packet that bind the same source checkpoint, repository, semantic version, and closure SHA-256.

The retained archive contains the exact closure ZIP, operator packet, audited source-manifest fingerprint, closure matrix, archive policy, and a SHA-256 closed-world manifest. `verify` re-runs closure/operator/source/policy verification; `rehearse` proves the FINAL release/evidence chain is recoverable from the archive alone; and `closeout` emits `OPERATIONAL_CLOSEOUT_READY` only after an ACTIVE retention window and matching recovery-rehearsal receipt. This archive recovery control is separate from the MySQL restore rehearsal in `docs/BACKUP_AND_RESTORE.md`.

The repository does not assert an official LSP Migas retention duration. Operators must supply `retention_until_utc` from approved organizational policy. See `docs/RELEASE_ARCHIVE_AND_RECOVERY.md`.

### GitHub repository materialization & external runtime target binding (Iteration 15.18)

Production-authoritative CI evidence must match a metadata-backed and **commit-bound** `EXTERNAL_RUNTIME_TARGET_BINDING.json`. Create the deterministic one-root Git history with `scripts/repository_materialization.py`, push that exact `main` commit, confirm GitHub remote HEAD from REST metadata, then bind the target. CI `GITHUB_SHA` must equal the materialized commit SHA. Manual repository-ID binding remains diagnostic/non-authorizing. See `docs/GITHUB_REPOSITORY_MATERIALIZATION.md`, `docs/GITHUB_REPOSITORY_ONBOARDING.md`, and `docs/EXTERNAL_RUNTIME_TARGET_BINDING.md`.
