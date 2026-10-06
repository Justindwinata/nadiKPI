# NADI Finalization Progress

## Iteration 1 — P0 baseline & correctness

Status: **implemented**

- Konsistensi status TUK diperbaiki menjadi `active / maintenance / inactive` pada request dan schema fresh-install.
- `warning_threshold` sekarang digunakan untuk status KPI `higher-is-better` dan `lower-is-better`.
- Regression coverage ditambahkan untuk kedua kasus tersebut.

## Iteration 2 — Certification lifecycle & single source of truth

Status: **implementation complete; runtime suite pending environment-capable verification**

Implemented:

- Lifecycle batch berurutan: `planned → document_review → assessment → decision → completed`.
- Timestamp `assessment_completed_at`, `decision_at`, `certificate_due_at`, dan `completed_at`.
- Deadline sertifikat otomatis 30 hari setelah keputusan.
- Business invariant hasil: `passed + failed + pending = total_assesi`; tahap keputusan memerlukan hasil final seluruh asesi.
- Event ledger `certificate_issuances` untuk penerbitan bertahap, referensi, waktu terbit, pencatat, dan audit trail.
- Cache batch `certificates_issued` dan `issued_on_time` dihitung ulang dari event ledger.
- Batch tidak dapat ditutup sebelum seluruh peserta kompeten memiliki sertifikat tercatat.
- Backlog dan overdue certificate dihitung pada dashboard sertifikasi.
- `CERT-VOLUME`, `CERT-PASS`, dan `CERT-SLA` kini system-derived dari data operasional.
- KPI sertifikasi system-derived tidak dapat dioverride lewat endpoint pengukuran manual atau CSV.
- UI memiliki alur `Catat hasil → Terbitkan → Selesaikan` dan menandai KPI derived sebagai `Otomatis`.
- Seeder diperbarui agar lifecycle demo konsisten dan penerbitan tepat waktu/terlambat mempunyai event bukti.
- Feature tests baru mencakup lifecycle, invariant hasil, penerbitan bertahap, derived KPI, proteksi manual override/CSV, warning threshold, dan status TUK maintenance.

Verification performed:

- PHP syntax scan seluruh `app/`, `database/`, `routes/`, dan `tests/`: **PASS**.
- Laravel boot dan route discovery: **PASS**; endpoint penerbitan sertifikat terdaftar.
- Pure-PHP threshold behavior check: **PASS** (`lower`: on-track/watch/critical dan `higher`: on-track/watch/critical).
- PHPUnit runtime: **BLOCKED BY AUDIT ENVIRONMENT**, karena PHP CLI di environment ini tidak memiliki `dom`, `mbstring`, `xmlwriter`, dan driver PDO database.
- Frontend build: source changes complete, tetapi clean `npm ci` di environment audit tidak selesai karena dependency registry/network; `node_modules` bawaan ZIP sebelumnya tidak portable dan gagal pada native Rolldown binding Linux.

## Iteration 3 — Finance transactional ledger & receivables

Status: **implementation complete; runtime suite pending environment-capable verification**

Implemented:

- `financial_records` diperkuat menjadi immutable ledger dengan `entry_kind`, `reversal_of_id`, `source_type`, `source_id`, dan `recorded_by`.
- Koreksi transaksi manual menggunakan reversal entry; transaksi asli tidak dihapus.
- Sub-ledger invoice `finance_invoices` dengan nomor invoice, pelanggan, tanggal terbit/jatuh tempo, nilai, status, dan link ke posting pendapatan ledger.
- Pembuatan invoice otomatis mem-posting revenue satu kali ke ledger (`source_type=finance_invoice`).
- Sub-ledger pembayaran `finance_payments`; overpayment ditolak dan status invoice berubah `issued → partially_paid → paid`.
- Reversal pembayaran mempertahankan payment asli, menyimpan alasan, lalu menghitung ulang saldo/status invoice.
- Void invoice memerlukan semua pembayaran aktif direversal terlebih dahulu dan membuat revenue reversal, bukan delete.
- Dashboard Finance menghitung revenue, expense, budget, margin, absorption, absolute budget variance, payments received, open receivable, overdue receivable, aging, dan reconciliation dari ledger/sub-ledger.
- Rekonsiliasi membandingkan total invoice aktif sampai akhir periode dengan posting revenue invoice bersih sampai periode yang sama.
- `FIN-REV`, `FIN-MARGIN`, dan `FIN-BUDGET` menjadi system-derived dari ledger keuangan dan tidak menerima input manual/CSV.
- UI Finance sekarang menyediakan `Buat invoice`, `Catat ledger`, `Bayar`, `Void invoice`, `Reverse pembayaran`, dan `Reverse ledger`.
- Seeder Finance diperbarui menjadi invoice-backed revenue dengan contoh paid, partially-paid, open, dan overdue receivable.
- Regression tests ditambahkan untuk posting invoice, payment/overpayment, reversal ledger, reversal payment + void invoice, receivable aging, derived finance KPI, dan proteksi manual/CSV override.

Verification performed:

- PHP syntax scan seluruh `app/`, `database/`, `routes/`, dan `tests/`: **PASS (73 files)**.
- Laravel boot dan finance route discovery: **PASS (7 finance endpoints)**.
- JSX syntax/transpile diagnostic menggunakan TypeScript parser: **PASS**.
- PHPUnit runtime: **BLOCKED BY AUDIT ENVIRONMENT** karena PHP CLI tidak memiliki `dom`, `mbstring`, `xmlwriter`, dan driver PDO database.
- Vite production build: **BLOCKED BY NON-PORTABLE NODE_MODULES**; ZIP awal hanya membawa native Rolldown binding platform lain sehingga binding Linux tidak tersedia. Clean dependency install tetap menjadi final verification gate.

## Iteration 4 — IT reliability & data-quality operational truth

Status: **implementation complete; runtime suite pending environment-capable verification**

Implemented:

- `IT-UPTIME`, `IT-MTTR`, dan `IT-DATA` menjadi system-derived dan tidak menerima override manual/CSV.
- Downtime dihitung sebagai union interval per layanan sehingga incident overlap tidak double-count.
- Interval insiden dipotong ke batas bulan/jendela laporan sehingga outage lintas bulan dialokasikan ke periode yang benar.
- Uptime tersedia agregat dan per layanan, lengkap dengan downtime hours, open incident count, dan evaluasi terhadap target layanan.
- MTTR menggunakan cohort insiden yang selesai pada periode dan durasi penuh `started_at → resolved_at`.
- Lifecycle insiden diperjelas menjadi `open → investigating → resolved`; penutupan wajib menyimpan resolution summary, resolver, timestamp, dan root cause opsional.
- Ledger `data_quality_runs` menyimpan dataset/source, total/valid records, missing required, duplicate, freshness failures, pencatat, dan audit trail.
- `IT-DATA` dihitung dengan weighted record ratio `Σ valid / Σ total`, bukan rata-rata skor per audit.
- Current-month reporting dipotong pada waktu sekarang; periode masa depan ditolak agar denominator uptime tidak memasukkan waktu yang belum terjadi.
- UI TI diperbarui dengan per-service reliability, incident lifecycle, modal resolusi, data-quality entry, quality trend, dan evidence register.
- Seeder kini membentuk evidence data-quality bulanan dan detail resolusi incident.
- Regression tests ditambahkan untuk overlap, month-boundary clipping, MTTR cohort, derived KPI, override protection, incident lifecycle, audit trail, dan quality validation.
- Konfigurasi timezone aplikasi dibuat eksplisit melalui `APP_TIMEZONE` dengan default `Asia/Jakarta`.

Verification performed:

- PHP syntax scan seluruh source/test: **PASS (77 files)**.
- Laravel boot dengan autoloader yang menunjuk working copy Iteration 4: **PASS**.
- Route discovery IT: **PASS (5 endpoints)**.
- Pure-PHP downtime union check: **PASS**; overlap 10:00–12:00 + 11:00–13:00 menjadi 3 jam, dan outage 31 Jan 23:00–1 Feb 03:00 terbagi ±1 jam Januari + 3 jam Februari.
- PHPUnit runtime: **BLOCKED BY AUDIT ENVIRONMENT** karena PHP CLI tidak memiliki `dom`, `mbstring`, `xmlwriter`, dan driver PDO database.
- JSX syntax/transpile diagnostic menggunakan TypeScript parser global: **PASS**.
- Fresh frontend dependency install: **BLOCKED/TIMED OUT** pada environment; install parsial tidak menghasilkan executable Vite sehingga `npm run build` belum dapat dijadikan PASS gate dan tetap harus diverifikasi sebelum final ZIP.


## Iteration 5 — Governance, compliance, provenance, and auditability

Status: **implementation complete; runtime suite pending environment-capable verification**

Implemented:

- Modul Governance lintas fungsi dengan role gate; admin governance dibatasi ke Pimpinan atau Mutu & Kepatuhan.
- Register temuan kepatuhan dan CAPA; CAPA selesai wajib evidence dan temuan hanya dapat ditutup setelah seluruh CAPA selesai dengan closure evidence.
- Register banding yang terhubung langsung ke batch sertifikasi, lengkap dengan tenggat, status, keputusan, dan ringkasan resolusi.
- Register obligation/lisensi dan expiry watchlist 90 hari untuk skema, TUK, asesor, dan obligation.
- `data_sources` sebagai hierarchy provenance dengan tipe sumber dan authority rank.
- `data_import_batches` untuk setiap CSV import dengan SHA-256, total/accepted/rejected rows, errors, status, dan reconciliation status.
- `kpi_measurements` manual/imported menyimpan source type/reference/import batch; seluruh KPI system-derived tetap tidak menerima override.
- Audit-log viewer lintas modul tersedia pada halaman Governance.
- Master data skema/TUK diperluas dengan tanggal validity/verification dan evidence reference.
- Akun demo Mutu & Kepatuhan ditambahkan, bersama synthetic governance evidence.
- Regression tests ditambahkan untuk RBAC governance, finding/CAPA closure invariant, appeal authorization/lifecycle, provenance checksum, dan expiry watchlist.

Verification performed:

- PHP syntax scan seluruh source/test: **PASS (86 files pada checkpoint Iteration 5 sebelum dokumentasi final)**.
- Laravel boot dan governance route discovery: **PASS (10 governance endpoints)**.
- JSX syntax/transpile diagnostic menggunakan TypeScript parser: **PASS**.
- PHPUnit runtime: **BLOCKED BY AUDIT ENVIRONMENT** karena PHP CLI tidak memiliki `dom`, `mbstring`, `xmlwriter`, dan driver PDO database.
- Vite production build: **BLOCKED BY DEPENDENCY ENVIRONMENT**; executable `vite` tidak tersedia pada node_modules hasil ZIP/install parsial. Fresh clean install tetap menjadi final gate.


## Iteration 6 — Identity, RBAC, administration, and production security

Status: **implementation complete; runtime suite pending environment-capable verification**

Implemented:

- RBAC granular dengan role `director`, `department_head`, `analyst`, dan `viewer`; default permission ditentukan per divisi dan dapat dioverride per user dengan alasan serta audit trail.
- Permission middleware per aksi untuk Sertifikasi, Finance, TI, Governance, KPI custom, action items, import, master data, dan user administration.
- Forced password change untuk akun baru/reset; route operasional terkunci sampai password diganti.
- Strong password policy untuk change/reset/create account dan pemutusan sesi lain setelah perubahan/reset password.
- Login throttling per account+IP serta per IP, dengan parameter di `config/nadi.php`.
- Authentication event ledger untuk login success/failed/throttled, inactive account, logout, dan password change.
- User-management API/UI untuk create, profile update, activate/deactivate, permission override, dan temporary password reset.
- Segregation of duties: reversal Finance, certificate issuance, provenance/reconciliation, audit view, dan user administration terpisah dari permission baca/input biasa.
- Guardrail admin: tidak dapat menonaktifkan akun aktif sendiri; minimal satu director aktif wajib tersisa; `users.manage` tidak dapat diberikan ke non-director.
- Security headers dan production-safe environment template dengan secure/encrypted session, debug off, serta demo mode off.
- CLI `php artisan nadi:create-admin` untuk bootstrap administrator production tanpa PrototypeSeeder.
- Frontend menu dan action controls mengikuti effective permission backend; halaman `Akun Saya` dan `Pengguna & Akses` ditambahkan.
- Regression tests security ditambahkan untuk viewer/analyst restrictions, forced-password flow, permission deny override, rate limiting, dan self-deactivation protection.

Verification performed:

- PHP syntax scan seluruh `app/`, `database/`, `routes/`, dan `tests/`: **PASS (95 files)**.
- Laravel boot dan route discovery dengan source Iteration 6: **PASS (44 API routes)**.
- Route security inventory: **40 routes** di belakang `password.changed`; **34 routes** menggunakan permission middleware.
- Permission matrix direct check: **PASS** untuk Finance analyst/viewer, Quality head, Certification head, dan Director.
- CLI bootstrap command discovery: **PASS** (`nadi:create-admin`).
- JSX syntax/transpile diagnostic: **PASS (0 diagnostics)**.
- PHPUnit runtime: **BLOCKED BY AUDIT ENVIRONMENT** karena PHP CLI tidak memiliki `dom`, `mbstring`, `xmlwriter`, dan driver PDO database.
- Vite production build: **NOT YET VERIFIED**; clean dependency install tidak selesai di environment dan `node_modules` bawaan ZIP berasal dari platform lain.
- Laravel cache optimization commands dan Pint: **BLOCKED BY AUDIT ENVIRONMENT** karena CLI PHP yang tersedia tidak memiliki `dom/xml` dan `mbstring`; tidak ada cache aplikasi parsial yang tertinggal.

Next target: real-data onboarding/integration dengan staging, mapping, validation, idempotency, source record lineage, reconciliation, dan import adapters untuk domain operasional.

## Iteration 7 — Real data onboarding & integration layer

Status: **implementation complete; runtime database suite pending environment-capable verification**

Implemented:

- Halaman **Integrasi Data** untuk onboarding data perusahaan melalui `stage → map → validate → publish`, bukan direct insert ke operational tables.
- 11 dataset contract: skema, TUK, asesor, batch sertifikasi, issuance sertifikat, invoice, pembayaran, financial ledger, IT service, IT incident, dan data-quality run.
- `data_import_batches` diperluas dengan dataset type, source headers, column mapping/defaults, validation summary, insert/update/skip counters, stage/publish timestamps, dan publisher.
- `integration_staging_rows` menyimpan raw payload, normalized payload, external key, row hash, row-level validation error, planned action, dan entity hasil publish.
- `integration_record_links` menjadi provenance/idempotency map `source + dataset + external_key → NADI entity`.
- `integration_profiles` menyimpan reusable mapping per source/dataset.
- File fingerprint SHA-256 mencegah batch file identik diproses ulang untuk source/dataset yang sama.
- Row hash membedakan `insert`, `update`, dan `skip`; file delta tidak menggandakan record lama. Duplicate yang di-skip tetap memperbarui `last_seen_at` provenance.
- Authority-rank enforcement mencegah source ber-rank lebih rendah mengubah entity yang sudah ditautkan ke source ber-authority lebih tinggi.
- Dataset ledger immutable (`certificate_issuances`, `finance_invoices`, `finance_payments`, `financial_records`, `data_quality_runs`) menolak overwrite record existing yang berubah.
- Publish default all-or-nothing; partial publish hanya tersedia sebagai flag API eksplisit dan meninggalkan reconciliation exception.
- Mapping header non-canonical dapat diperbaiki setelah staging dan divalidasi ulang; mapping dapat disimpan sebagai profile.
- Dependency validation memastikan batch sertifikasi mengacu ke skema/TUK/asesor yang sudah tersedia, payment mengacu invoice, issuance mengacu batch, dan incident mengacu layanan IT.
- Certification import mengikuti lifecycle invariant; `completed` tidak boleh dipublish sebelum issuance peserta kompeten lengkap.
- Imported invoice otomatis mem-posting revenue dan dilindungi dari direct ledger reversal; koreksi tetap melalui Void Invoice.
- Governance reconciliation tidak dapat menandai integration batch secara manual; reconciliation integration berasal dari hasil publish.
- Permission baru `integrations.view` dan `integrations.manage`; default diberikan kepada kepala fungsi, sedangkan domain scoping tetap membatasi dataset Sertifikasi/Finance/IT masing-masing. Pimpinan/Quality provenance admin dapat mengelola lintas domain.
- PrototypeSeeder menambahkan contoh `SRC-LEGACY-CERT` dan reusable TUK mapping profile.
- Feature regression test ditambahkan untuk staging/publish/provenance, custom header mapping, delta idempotency, immutable finance protection, dan cross-domain authorization.
- Dokumentasi operasional ditambahkan pada `docs/INTEGRATION_ONBOARDING.md`.

Verification performed:

- PHP syntax scan source/test: **PASS**.
- Laravel boot: **PASS (13.31.0)**.
- Integration route discovery: **PASS (8 endpoints; total API routes 52)**.
- Integration catalog boot smoke: **PASS (11 dataset contracts)**.
- Permission default smoke: **PASS** — department head Finance/Certification/Quality memiliki integration view/manage; analyst tidak mendapat onboarding permission secara default.
- JSX syntax/transpile diagnostic menggunakan TypeScript parser global: **PASS**.
- PHPUnit/runtime database verification: **BLOCKED BY AUDIT ENVIRONMENT** karena PHP CLI tidak memiliki driver PDO database dan extension PHPUnit (`dom`, `mbstring`, `xmlwriter`).
- Vite production build: **BLOCKED BY DEPENDENCY ENVIRONMENT**; clean portable Node dependency install masih menjadi final release gate.

## Iteration 8 — KPI catalog, master-data lifecycle, and configuration audit

Status: **implementation complete; runtime suite pending environment-capable verification**

Implemented:

- Katalog KPI terpisah dengan hak baca/kelola granular (`kpi.catalog.view` dan `kpi.catalog.manage`).
- KPI custom dapat dibuat dari UI tanpa perubahan source code; KPI bawaan tetap menggunakan `calculation_mode=system` dan tidak dapat diubah menjadi manual.
- `kpi_configurations` menyimpan versi target, warning threshold, bobot, owner, periode efektif, status aktif, alasan perubahan, dan pencatat.
- Konfigurasi baru bersifat append-only; versi lama ditutup sehari sebelum periode baru sehingga histori target/bobot tidak tertimpa.
- Dashboard memilih konfigurasi KPI berdasarkan periode laporan, sehingga laporan historis menggunakan target yang berlaku pada periode tersebut.
- Pengukuran KPI manual dan CSV menggunakan target snapshot dari konfigurasi yang berlaku pada tanggal pengukuran serta menolak KPI yang tidak aktif/tidak memiliki konfigurasi pada periode tersebut.
- Katalog KPI menampilkan riwayat konfigurasi, owner, alasan perubahan, user pencatat, dan timestamp.
- Master data skema, TUK, asesor, dan layanan IT sekarang mendukung edit, archive, dan restore tanpa delete.
- Archive menyimpan `archived_at`, `archived_by`, dan `archive_reason`; restore mempertahankan histori dan mengaktifkan kembali record.
- Dependency guard menolak archive skema/TUK/asesor yang masih dipakai batch belum selesai dan layanan IT yang masih memiliki insiden unresolved.
- Record master yang diarsipkan dikeluarkan dari pilihan batch/incident baru serta tidak valid sebagai dependency onboarding operasional.
- Perubahan metadata, archive, restore, serta versi KPI seluruhnya masuk audit log.
- Regression tests baru mencakup historical KPI target, cross-department KPI authorization, custom KPI target snapshot, master update/archive/restore, dan dependency protection.

Verification performed:

- PHP syntax scan seluruh source/test: **PASS (107 files)**.
- Laravel boot: **PASS (Laravel 13.31.0)**.
- API route discovery: **PASS (59 routes; 4 KPI Catalog endpoints; 5 Master Data endpoints)**.
- Forced-password middleware terdeteksi pada 55 API route dan granular permission middleware pada 46 route.
- JSX syntax/transpile diagnostic: **PASS (0 diagnostics)**.
- PHPUnit runtime: **BLOCKED BY AUDIT ENVIRONMENT** karena PHP CLI tidak memiliki `dom`, `mbstring`, `xmlwriter`, dan driver PDO database.
- Clean `npm ci`: **BLOCKED/TIMED OUT** pada environment; proses dihentikan dan install parsial dibersihkan sehingga tidak ada background process tertinggal.

## Iteration 9 — Decision workflow, alerting, escalation, and management review

Status: **implementation complete; runtime database suite pending environment-capable verification**

Implemented:

- `risk_signals` sebagai persistent, idempotent alert/exception register dengan fingerprint sumber, severity, department ownership, acknowledgement, escalation, dan resolution metadata.
- `RiskSignalService` menyinkronkan exception dari KPI watch/critical, overdue compliance finding, overdue CAPA, overdue receivable, overdue certificate backlog, high/critical unresolved IT incident, dan obligation expiry ≤90 hari.
- Kondisi yang sama tidak membuat signal duplikat; signal managed yang sumbernya pulih ditutup otomatis. Manual resolution akan terbuka kembali jika rule sumber masih aktif pada sinkronisasi berikutnya.
- Pusat Keputusan baru dengan metrik active/critical/unacknowledged/escalated signals, risk register, action board, dan management-review history.
- Risk signal dapat di-acknowledge, dieskalasi bertingkat, dibuatkan action item, atau diberi resolution note dengan seluruh perubahan masuk audit log.
- `action_items` diperluas dengan `risk_signal_id`, decision reference, acknowledgement, escalation, updated-by, resolution note, dan resolution evidence.
- Action `completed` kini terminal dan wajib mempunyai resolution note + evidence; closure linked action juga menutup linked signal, dengan risk engine tetap dapat membuka kembali jika kondisi sumber belum pulih.
- Eskalasi signal diteruskan ke action item aktif yang tertaut untuk menjaga konsistensi execution board.
- `management_reviews` dan `management_review_items` menyimpan agenda exception, owner, due date, keputusan item, summary, keputusan akhir, approval, dan closure history.
- Management Review menggunakan lifecycle forward-only `draft → in_review → approved → closed`; approval wajib memiliki keputusan tertulis dan item terkunci setelah approval.
- Permission baru `decisions.view`, `decisions.manage`, dan `decisions.review`; director memiliki seluruh akses, kepala divisi mengelola signal divisinya, dan Quality Head dapat melakukan review lintas domain.
- PrototypeSeeder menambahkan historical closed management-review evidence agar UI demonstrasi memiliki review history.
- Regression tests ditambahkan untuk signal idempotency, department scoping, acknowledgement/escalation, linked action, completion evidence, dan management-review lifecycle.

Verification performed:

- PHP syntax scan seluruh source/test: **PASS (114 files sebelum final documentation-only changes; final lint dijalankan kembali pada penutupan checkpoint)**.
- Laravel boot dan API route discovery: **PASS (67 API routes; 8 Decision endpoints)**.
- Route security inventory: **63 routes** di belakang `password.changed`; **54 routes** menggunakan granular permission middleware.
- JSX syntax/transpile diagnostic menggunakan TypeScript parser global: **PASS (0 diagnostics)**.
- PHPUnit/runtime database verification: **BLOCKED BY AUDIT ENVIRONMENT** karena PHP CLI tidak memiliki extension PHPUnit (`dom`, `mbstring`, `xmlwriter`) dan driver PDO database.
- Vite production build tetap menjadi release gate final karena clean portable dependency installation belum tersedia pada audit environment.

## Iteration 10 — Notification center, scheduled monitoring, and realtime delivery

Status: **implementation complete; runtime database suite and production Vite build pending environment-capable verification**

Implemented:

- `user_notifications` sebagai inbox per-user dengan fingerprint idempotent, severity, link ke risk signal/action/review, read timestamp, dan acknowledgement timestamp.
- `nadi:monitor-risks` mengorkestrasi risk sync, auto-escalation critical signal, overdue-action reminder, management-review reminder, notification generation, dan retention cleanup.
- Laravel scheduler menjalankan monitor setiap menit dengan overlap protection.
- Critical signal yang belum diakui dieskalasi otomatis berdasarkan `NADI_CRITICAL_ESCALATE_LEVEL1_MINUTES` dan `NADI_CRITICAL_ESCALATE_LEVEL2_MINUTES`; escalation system dicatat pada audit log dan linked action ikut dinaikkan metadata escalation/priority-nya.
- Reminder action overdue dibuat satu kali per hari per penerima dan tidak membuat duplikasi dalam hari yang sama.
- Notification recipients mengikuti effective `decisions.view`, ownership divisi, Pimpinan, dan Quality oversight; user lintas divisi yang tidak berhak tidak menerima signal domain lain.
- Notification API mendukung inbox, mark read, mark all read, dan acknowledgement dengan owner isolation.
- SSE endpoint `/api/notifications/stream` mendukung `Last-Event-ID`, reconnect browser, heartbeat, dan stream window terbatas; frontend memiliki fallback sync setiap 60 detik.
- Notification Center UI ditambahkan ke Glance Header dan halaman identity/admin dengan unread badge, critical-unacknowledged counter, koneksi realtime indicator, direct navigation ke Pusat Keputusan, read, dan acknowledge.
- Read notification retention default 180 hari agar reminder harian tidak membuat tabel bertumbuh tanpa batas; audit log tidak ikut dipurge.
- Dokumentasi deployment/operasional ditambahkan pada `docs/MONITORING_AND_NOTIFICATIONS.md`.
- Regression tests ditambahkan untuk recipient scoping, idempotency, critical auto-escalation, daily overdue reminder, dan notification ownership/read/acknowledge.

Verification performed:

- PHP syntax scan seluruh `app/`, `database/`, `routes/`, dan `tests/`: **PASS (120 files)**.
- JSX parser diagnostic: **PASS (0 diagnostics)**.
- Laravel boot/route discovery dengan dependency baseline: **PASS (72 API routes; 5 notification endpoints)**.
- Security inventory: **68 API routes** di belakang `password.changed`; **54 routes** memakai granular permission middleware.
- Scheduler discovery dengan `CACHE_STORE=array`: **PASS** — `nadi:monitor-risks` terjadwal setiap menit.
- PHPUnit/database runtime: **BLOCKED BY AUDIT ENVIRONMENT** karena PHP CLI belum memiliki `dom`, `mbstring`, `xmlwriter`, dan PDO database driver.
- Vite production build tetap menjadi final release gate karena portable clean dependency install belum tersedia pada audit environment.

## Iteration 11 — Reporting, executive export, and management evidence pack

Status: **implementation complete; runtime database suite and production Vite build pending environment-capable verification**

Implemented:

- `report_snapshots` stores immutable report payload, reporting period, generator, optional department/review context, source manifest, schema version, and SHA-256 checksum.
- Report catalog supports Executive, Department Performance, Certification, Finance, IT, Governance, Risk & Action Register, and Management Review reports, with availability derived from existing domain permissions.
- Executive report combines KPI overview with Certification/Finance/IT summaries, governance evidence, risk/action register, and management review context.
- Department report is forcibly scoped to the user's own department for non-directors; director may select a department.
- KPI configuration snapshot persists actual, target, warning threshold, weight, owner, status, effective date, and source/calculation type used at generation time.
- SHA-256 binds canonical `payload + source_manifest`, not only display values.
- Provenance manifest captures active data-source registry and import batches including source authority, file SHA-256, accepted/rejected rows, and reconciliation status.
- Exports use stored snapshot only: JSON, CSV, printable A4 HTML, and Evidence Pack ZIP.
- Dependency-free ZIP writer implemented and validated with standard `unzip -t`.
- Evidence Pack includes manifest, report JSON/CSV, printable HTML, KPI CSV, provenance CSV, and table-specific CSV evidence.
- CSV export escapes spreadsheet-formula prefixes (`=`, `+`, `-`, `@`) to reduce formula-injection risk.
- `reports.view` and `reports.export` permissions added; export/print is denied by default to analyst/viewer roles while heads/director receive export rights.
- Report generation, printable view, and export actions are written to `audit_logs`.
- Frontend **Laporan** workspace supports period/type selection, snapshot generation, checksum display, KPI snapshot preview, history, and export controls.
- Feature tests added for immutable snapshots, cross-domain authorization, forced department scoping, export permission, and ZIP evidence generation.
- Documentation added at `docs/REPORTING_AND_EVIDENCE.md`.

Verification performed:

- PHP syntax scan: **PASS (127 files, 0 syntax errors)**.
- React JSX parse/transpile using global TypeScript parser: **PASS (0 diagnostics)**.
- Laravel boot and route discovery with clean copied vendor dependencies: **PASS (77 API routes; 5 reporting endpoints; 73 forced-password protected; 59 permission-gated)**.
- Dependency-free Evidence Pack ZIP: **PASS** via `unzip -t`; all generated entries tested OK.
- CSV formula-injection protection smoke test: **PASS** (`=evil.csv` exported as an escaped cell).
- PHPUnit `ReportingWorkflowTest`: **BLOCKED BY AUDIT ENVIRONMENT** before testcase execution because PHP CLI lacks `dom`, `mbstring`, and `xmlwriter` (PDO database driver is also unavailable for full DB verification).
- Offline clean `npm ci`: **BLOCKED** because `yargs-parser-22.0.0.tgz` is not present in npm cache; partial `node_modules` was removed.

## Iteration 12 — Final release engineering and deployment hardening

Status: **release-engineering implementation complete; final runtime release candidate remains gated by environment-capable MySQL/PHPUnit/Vite verification**

Implemented/fixed:

- Added `ReleaseReadinessService`, public session-independent `/api/health/ready`, and `nadi:release-check` for PHP/runtime, production configuration, frontend build, database connectivity, and pending-migration checks.
- Separated `/up` liveness from dependency-aware readiness. Readiness returns structured `503` when the application is not deployable and does not expose exception/database secrets.
- Production frontend demo mode now defaults to disabled; external Fontshare/Bunny-font dependencies were removed for self-hosted/offline-first runtime assets.
- Hardened production response headers with CSP, frame denial, MIME sniffing protection, restrictive permission policy, COOP/CORP, and HSTS on HTTPS production traffic.
- Enabled trusted-host validation and optional explicit trusted-proxy configuration.
- Protected `PrototypeSeeder` from accidental production execution; first production identity is bootstrapped using `nadi:create-admin`.
- Removed stale generated Composer/Laravel provider-cache artifacts from source packaging and created required runtime storage/cache directories with safe placeholders.
- Removed unused Laravel welcome page and changed `robots.txt` to disallow indexing of the private management application.
- Added `scripts/verify-release.sh`, `scripts/deploy-production.sh`, `docs/DEPLOYMENT_AND_RELEASE.md`, and `docs/BACKUP_AND_RESTORE.md`.
- Added MySQL-backed CI release gate (`.github/workflows/release-gates.yml`) covering Composer install, clean npm install/build, migrations, PHPUnit, Pint, Laravel cache checks, and release readiness.
- Added deterministic `scripts/package_release.py`; packaging is refused when `public/build/manifest.json` is absent/invalid. Artifact excludes `.env`, demo DB, runtime logs/cache, vendor/node_modules, and development/AI tooling and contains `RELEASE_MANIFEST.json` plus a ZIP SHA-256 sidecar.
- Release packager was smoke-tested with a controlled build artifact: ZIP integrity passed via `unzip -t`, forbidden runtime/development files were absent, and release manifest was present.
- Added feature coverage for readiness/liveness semantics.
- Static migration audit found **26 migration files**, **41 created tables**, and no duplicate `Schema::create` definitions.
- HTTP smoke test: `/up` returned `200`; `/api/health/ready` returned structured `503` in the current incomplete audit runtime, demonstrating safe dependency failure handling rather than a generic `500`.

Current audit-environment constraints:

- PHP CLI lacks `dom`, `mbstring`, `xml`, `xmlwriter`, and a PDO database driver, so PHPUnit, Pint, production cache generation, and MySQL runtime migration cannot be executed here.
- Debian package registry is unavailable from the audit container, so missing PHP extensions/MySQL cannot be installed locally.
- Clean npm dependency acquisition remains unavailable; the original ZIP contains Darwin/ARM native frontend bindings and cannot provide a Linux Vite production build. Offline npm cache is incomplete.

These constraints are now explicit release failures: `nadi:release-check --production`, CI, and the release packager prevent treating such an environment as deployable or producing a final release ZIP without a real production build.

Final Iteration 12 verification snapshot:

- PHP syntax: **PASS — 146 PHP files, 0 syntax errors** across application, configuration, migrations, routes, tests, and bootstrap source.
- Laravel boot: **PASS — Laravel Framework 13.31.0** using the locally available dependency baseline.
- API inventory: **78 routes**; public readiness route has no session middleware; **73** operational routes remain behind forced-password protection and **59** are granular-permission gated.
- Frontend source parse: **PASS — 0 JSX/Vite diagnostics** with the available TypeScript parser.
- Accessibility last-mile source hardening added a keyboard skip link to `#main-content` and explicit labels for mobile navigation controls; full browser/Lighthouse visual accessibility validation remains a production-build QA gate.
- Migration static audit: **26 migration files / 41 table-create declarations / 0 duplicate `Schema::create` definitions**.
- Readiness HTTP smoke: `/up` **200**; `/api/health/ready` **503** in the intentionally incomplete local runtime, with no database exception/credential leakage.
- Production-config readiness smoke with DB/build skipped fails only on missing local runtime extensions `dom`, `mbstring`, `xml`, and `pdo_mysql`, demonstrating that production security/config checks otherwise pass with a safe configuration.
- Release packager controlled-build smoke: standard ZIP validation **PASS**, `RELEASE_MANIFEST.json` present, Vite manifest present, development/runtime secrets excluded (runtime `.gitignore` placeholders intentionally retained).
- PHPUnit final attempt: **BLOCKED BEFORE TEST EXECUTION** because `dom`, `mbstring`, and `xmlwriter` are unavailable.
- Offline `npm ci` final attempt: **BLOCKED** because `yargs-parser-22.0.0.tgz` is not present in the environment cache.


## Iteration 13 — Final release candidate validation and cross-module remediation

Status: **source release candidate remediated and statically consistent; final runtime release remains blocked only by audit-environment MySQL/PHPUnit/Vite prerequisites**

Release-candidate audit was performed across authentication, operational ledgers, derived KPI, risk/decision workflow, notification delivery, immutable reporting, and release packaging. The following cross-module defects were found and fixed rather than deferred:

- Current-period Certification and Finance KPI calculations now cap source transactions at the actual report `as_of` time instead of including future-dated transactions later in the same month.
- Historical Finance reports preserve payments that were active at the report end even if those payments were reversed in a later period.
- IT uptime now supports explicit `monitoring_started_at`; uptime denominators and downtime clipping are limited to the service monitoring/archive window instead of assuming a service was monitored for the full reporting period.
- Financial-record reversal now has a database-level unique invariant on `reversal_of_id` plus row locking; payment reversal also locks the payment/invoice state before mutating it.
- Invoice void validation now executes after `lockForUpdate()` inside one transaction, preventing a concurrent payment from racing the void invariant.
- Risk & Action report snapshot authorization was tightened: company-wide snapshots are director-only and department snapshots require matching department scope; Management Review remains governed by its dedicated review permission.
- Historical Certification reports now calculate certificate backlog and active batch state as-of the report end, so later issuance/completion cannot rewrite earlier evidence.
- Risk/Action snapshots now expose `status_as_of`; later acknowledgement/escalation/resolution/completion does not change the meaning of an older report.
- Governance snapshots now compute findings, CAPA, and appeals as-of the report end, including closure/completion/decision timestamps that occur after the reporting period.
- Frontend source no longer embeds demo email/password literals. In demo mode the login screen requests accounts from `/api/demo-access`; the endpoint is `404` unless server-side demo mode is enabled, and the server has no password fallback: `NADI_DEMO_PASSWORD` must be explicitly configured for demo use.
- Evidence Pack ZIP and CSV formula-injection protection were revalidated after historical-report changes.

Additional release-candidate verification:

- PHP syntax scan: **PASS — 148 PHP files, 0 syntax errors**.
- Frontend source parse: **PASS — 0 diagnostics for `resources/js/app.jsx` and `vite.config.js`**.
- Laravel API inventory: **79 API routes**; **73** operational routes remain behind forced-password protection and **59** use granular permission middleware. `/api/demo-access` is public-but-guest-only and disabled unless demo mode is enabled.
- Migration/model audit: **28 migration files / 41 created tables / 0 duplicate `Schema::create` definitions / 34 models / 0 `$fillable` references to missing columns**.
- Controller-route audit: all controller actions referenced by routes exist; all permission strings used by middleware exist in the user permission catalog.
- Package manifest audit: root `package.json` dependencies/devDependencies match the `package-lock.json` root contract.
- HTTP smoke with trusted `Host: localhost`: `/up` returned **200**; `/api/health/ready` returned structured **503** in the intentionally incomplete audit runtime and leaked no SQLSTATE, credential, application-key, stack-trace, or exception detail.
- Evidence Pack smoke: **PASS** with standard `unzip -t`; CSV cells beginning with spreadsheet formula prefixes remain escaped.

Current environment blockers remain real release failures rather than waived checks:

- PHPUnit cannot start because PHP CLI lacks `dom`, `mbstring`, and `xmlwriter`.
- No PDO MySQL driver/MySQL service is available, so real MySQL migration and transactional concurrency tests cannot run in this container.
- Network access to the npm registry fails with DNS `EAI_AGAIN`; offline cache is missing `yargs-parser-22.0.0.tgz`, so a clean Linux Vite production build cannot be executed here.

The repository release controls continue to treat those conditions as blocking: `nadi:release-check --production`, CI, and `package_release.py` prevent a final production package from being declared deployable without the required runtime/build evidence.

## Iteration 14.5 — Security, atomicity, and release-gate hardening

Status: **source hardening complete; mandatory full runtime gates remain pending environment-capable execution**

Implemented/fixed:

- Enforced report snapshot immutability at the Eloquent model layer: update/delete attempts now fail, rather than relying only on UI/controller conventions.
- Added regression coverage proving report snapshot update/delete is rejected.
- Notification acknowledgement now locks the notification row and writes state change + audit log atomically.
- Added `active.user` middleware so deactivated accounts with stale/non-database sessions cannot use `/api/me`, change password, or enter operational modules; logout remains available.
- Added stale-session regression coverage for inactive users.
- User administration mutations (profile/role update, activation/deactivation, permission overrides, password reset) now use transactions and row locks; the "at least one active director" invariant locks active director rows before evaluating the guard.
- Governance lifecycle mutations (finding close/update, CAPA create/update, appeal create/update, obligation/source creation, reconciliation) now keep state changes and audit evidence in the same transaction and use row locks for concurrency-sensitive transitions.
- Master-data update/archive/restore now lock the target row and evaluate archive state/dependency guards inside the transaction.
- Report generation + audit evidence are now committed atomically.
- Certification lifecycle invariants are re-checked after `lockForUpdate()` so a stale concurrent request cannot regress lifecycle state or reduce `passed` below certificates already issued.
- Integration publish re-checks `published_at` and invalid-row state after locking the batch, closing the double-publish race.
- Partial integration publish errors no longer persist raw exception messages; internal exceptions are reported server-side and user/database messages use a sanitized reference.
- Legacy KPI CSV import failure handling now records failure + audit atomically and no longer persists raw exception messages.
- Integration mapping/profile writes now keep mutation + audit atomic; mapping revalidation locks the batch and re-checks publish state.
- KPI configuration versioning now locks the parent KPI and a database unique invariant enforces `(kpi_definition_id, effective_from)`.
- KPI definition update + audit are now atomic and row-locked.
- Removed fixed demo password material from `.env.example`, README, and macOS helper. Local demo helper now generates a random setup password; test-only seeding uses an explicit ephemeral PHPUnit value.
- Added runtime preflight script for MySQL/SQLite modes, exact Node/npm engine checks, required PHP extensions, and PHP >= 8.4.1 for the current locked dependency set.
- Added real-MySQL assertion script for CI (Laravel connection, PDO driver, MySQL >=8).
- CI release gate now runs runtime preflight, clean installs, Vite build, real MySQL assertion, `migrate:fresh`, PHPUnit on MySQL, Pint, production release-check, cache smoke, HTTP/security smoke, and deterministic package smoke.
- Release readiness now checks PHP >=8.4.1 and `xmlwriter` in addition to the previous runtime requirements.
- Release packager is now reproducible-by-construction: normalized ZIP timestamps/permissions, stable ordering, `SOURCE_DATE_EPOCH`, active output-directory exclusion, Vite asset existence validation, runtime `.env` exclusion, credential regression guard, and deterministic filename.
- macOS local helper now executes SQLite runtime preflight before dependency/setup work.

Verification performed in the current runner:

- PHP syntax: **PASS — 151 PHP files, 0 syntax errors**.
- Laravel API inventory: **79 API routes**.
- Test inventory: **86 test methods**.
- Migration inventory: **29 migration files / 41 created tables / 0 duplicate `Schema::create` definitions**.
- Model inventory: **34 models**.
- Historical fixed demo password regression scan: **0 hits outside excluded dependency/runtime paths**.
- Shell syntax for release/deploy/preflight/macOS helper: **PASS**.
- Python packager compile: **PASS**.
- Controlled deterministic packager smoke: **PASS** — two independent archives from identical source and `SOURCE_DATE_EPOCH` produced byte-identical SHA-256 `58bbeb307b1f9162cf38daef266fb23e3a77650e55f1b9878529b0aad43542bf`; `unzip -t` passed; forbidden runtime/dependency entries were absent.
- Laravel `route:list` boot: **PASS** using the lockfile-matching cached vendor tree solely for source/runtime inspection.
- PHPUnit execution remains **BLOCKED BEFORE TEST EXECUTION** because the current PHP CLI lacks `dom`, `mbstring`, and `xmlwriter`.
- MySQL runtime remains **BLOCKED** because `pdo_mysql` and MySQL service are unavailable in the current runner.
- Clean frontend dependency/build verification remains a mandatory open gate until a network/cache-capable runner can execute `npm ci && npm run build`.

Current release status after Iteration 14.5:

```text
SOURCE HARDENING / CROSS-MODULE INTEGRITY   PASS
STATIC SOURCE VALIDATION                    PASS
DETERMINISTIC PACKAGER ENGINE               PASS
REAL MYSQL migrate:fresh                    PENDING
FULL PHPUnit ON MYSQL                       PENDING
PINT RUNTIME                                PENDING
REAL CLEAN VITE BUILD                       PENDING
PRODUCTION RELEASE-CHECK WITH REAL DB       PENDING
FULL BROWSER E2E                            PENDING
FINAL PRODUCTION PASS                       NOT DECLARED
FINAL ZIP                                   NOT CREATED
```

## Iteration 14.6 — Authorization boundaries, lifecycle concurrency, and integration staging idempotency

Status: **source hardening complete; full runtime release gates remain pending an environment with Composer, required PHP extensions, MySQL 8, and clean frontend dependency access**

Implemented/fixed:

- Reporting snapshot visibility now depends on the viewer's **current** domain permission, not on historical authorship. Revoking `finance.view`, `certification.view`, `it.view`, or `governance.view` removes previously generated domain snapshots from history/detail access for that user.
- Department reports are restricted to the viewer's own department; Risk & Action snapshots require current `decisions.view` plus department scope; Management Review snapshots require `decisions.review`. The previous `generated_by` authorization bypass was removed.
- Added regression coverage proving a revoked Finance permission immediately blocks an existing Finance snapshot and removes it from report history.
- Risk-signal acknowledge/escalate/resolve/action creation now re-authorize and re-check lifecycle state after `lockForUpdate()`. Signal escalation locks the RiskSignal before linked ActionItems to provide one consistent lock order.
- ActionItem transitions now validate the locked current state, and linked risk resolution uses the same RiskSignal -> ActionItem lock order to reduce stale transitions and deadlock risk.
- Management Review status and item mutations now execute forward-only validation after locking the review/item rows; approved/closed reviews cannot receive stale concurrent edits.
- Finance payment reversal now uses the same `invoice -> payment` lock order as payment creation/void flows, eliminating an avoidable deadlock pattern between concurrent finance transactions.
- IT incident `investigate` and `resolve` lifecycle checks now execute after row locking so stale concurrent requests cannot regress or double-resolve incidents.
- Certification batch updates now re-check result completeness and assessment/decision chronology after locking. Existing certificate issuance also prevents moving `decision_at` later than an already-issued certificate timestamp.
- Password self-change now locks the current user and re-checks the submitted current password hash after the lock, preventing a stale session from overwriting a concurrent administrator password reset.
- Integration read authorization now correctly distinguishes `integrations.view` from `integrations.manage`: read-only users can list/view authorized-domain batches while stage/mapping/publish/profile mutation still requires manage permission.
- Integration staging now locks the selected DataSource and repeats the identical-file idempotency check inside the same transaction. Concurrent identical uploads can no longer both pass the pre-create check.
- Added regression coverage for closed risk signals, certification decision/issuance chronology, read-only integration access, and duplicate integration file staging.

Verification performed in the current runner:

- PHP syntax: **PASS — 150 PHP files scanned, 0 syntax errors** across app/bootstrap/config/database/routes/tests.
- Test inventory: **91 test methods**.
- Migration inventory: **29 migration files / 41 `Schema::create` declarations / 0 duplicate table creates**.
- Model inventory: **34 models**.
- Historical fixed demo password literal: **0 source hits**.
- Shell syntax (`scripts/*.sh` and macOS helper): **PASS**.
- Python release packager compile: **PASS**.
- JSON (`composer.json`, `package.json`, `FINAL_STATUS.json`) and `phpunit.xml` parsing: **PASS**.
- GitHub release workflow YAML parse: **PASS**.
- Controlled release-packager reproducibility smoke after the Iteration 14.6 changes: **PASS**. Two packages from identical source/`SOURCE_DATE_EPOCH` produced the same SHA-256 `bcbbaf6013583dc7bea56a3bcc2fb9e7678f288ac55cdc1a1b6aa825bb22d540`; `RELEASE_MANIFEST.json` was present and forbidden `.env`/vendor/node_modules/database/log entries were absent.
- Runtime preflight remains **FAIL by environment**, specifically: Composer CLI missing; PHP `mbstring`, `dom`, `xml`, `xmlwriter`, and `pdo_mysql` missing. PHP 8.4.23, Node 22.16.0, npm 10.9.2, and the other required base extensions pass.

Current release state after Iteration 14.6:

```text
AUTHORIZATION / OBJECT-LEVEL REPORT SCOPE   HARDENED
DECISION / ACTION CONCURRENCY               HARDENED
FINANCE LOCK ORDER                          HARDENED
IT LIFECYCLE CONCURRENCY                    HARDENED
CERTIFICATION CHRONOLOGY                    HARDENED
PASSWORD RESET/SELF-CHANGE RACE             HARDENED
INTEGRATION VIEW/MANAGE RBAC                HARDENED
INTEGRATION STAGING IDEMPOTENCY             HARDENED
STATIC SOURCE VALIDATION                    PASS
DETERMINISTIC PACKAGER ENGINE               PASS
REAL MYSQL migrate:fresh                    PENDING
FULL PHPUnit ON MYSQL                       PENDING
PINT RUNTIME                                PENDING
REAL CLEAN VITE BUILD                       PENDING
PRODUCTION RELEASE-CHECK WITH REAL DB       PENDING
FULL BROWSER E2E                            PENDING
FINAL PRODUCTION PASS                       NOT DECLARED
FINAL ZIP                                   NOT CREATED
```

## Iteration 14.7 — Business-invariant, authorization TOCTOU, and integration authority closure

Status: **source hardening complete for this iteration; mandatory production runtime gates remain blocked by the current runner prerequisites**

Implemented/fixed:

- RiskSignal auto-resolution now occurs only after **all** linked ActionItems are completed. Completing one of several linked actions can no longer prematurely close the operational risk.
- Management Review now enforces the agreed sequential lifecycle `draft -> in_review -> approved -> closed`; skipped lifecycle transitions are rejected after row locking. Review items are forward-only and cannot be reopened after completion.
- The Management Review frontend now exposes only the current/next legal review and item states, aligning the UI with backend lifecycle invariants instead of offering transitions that the API must reject.
- New KPI measurements now lock the parent KPI before reading the applicable versioned configuration. Archived/inactive custom KPI cannot accept new measurements and target snapshots are derived from the locked effective configuration.
- Risk-signal synchronization uses race-safe `firstOrCreate` semantics. Scheduled critical-signal escalation now locks and re-checks status/severity/acknowledgement before escalating, preventing false escalation after a concurrent acknowledgement. Notification creation is race-safe as well.
- Restored a previously claimed-but-missing `scripts/audit_frontend_api_contract.py` release gate. The checker statically compares React API use against Laravel route/method contracts and is now executed by release CI.
- Release packaging excludes Python/test/lint caches (`__pycache__`, `.pytest_cache`, `.ruff_cache`, `.mypy_cache`, `.coverage`) in addition to the existing dependency/runtime exclusions.
- User administration now closes authorization TOCTOU windows. Sensitive mutations lock the authenticated administrator and target user in deterministic ID order, then re-check active state and current `users.manage` permission before role/status/permission/password changes. Director-only restrictions and self-modification guards are re-evaluated against locked current rows.
- Legacy manual KPI CSV import now opens/parses the source safely before mutation, identifies itself as `kpi_manual_measurements`, locks the current actor and all referenced KPI definitions in deterministic code order, then derives configuration/target snapshots only after those locks. Deadlock retries use attempt-local counters so a rolled-back attempt cannot double-count imported/rejected rows.
- Added regression coverage that a manual custom-KPI CSV import stores the effective configuration target snapshot and provenance batch type.
- Integration publish now re-checks source authority **after locking the mutable target entity**. A low-authority batch that was valid at staging cannot overwrite an entity that gains higher-authority provenance before publish.
- Integration staging/publish/profile/mapping mutation paths now re-check the current actor's active state, `integrations.manage`, and domain management permission inside their transaction/lock boundary rather than relying exclusively on pre-transaction middleware/controller authorization.
- Added regression coverage for the staging-to-publish authority TOCTOU scenario: higher-authority provenance introduced after staging blocks the lower-authority publish and leaves the entity unchanged.

Verification performed after all Iteration 14.7 source changes:

- PHP syntax: **PASS — 150 PHP files, 0 syntax errors**.
- Frontend/API static contract: **PASS — 79 Laravel routes / 78 frontend API contracts / 0 missing contracts**.
- Python release scripts compile: **PASS**.
- Shell/macOS helper syntax: **PASS**.
- JSON, `phpunit.xml`, and GitHub workflow YAML parse: **PASS**.
- Migration inventory: **29 migration files / 41 `Schema::create` declarations / 0 duplicate table creates**.
- Model inventory: **34 models**.
- Test inventory: **95 test methods** (execution still pending an environment-capable PHPUnit/MySQL runtime).
- Historical fixed demo password regression scan: **0 source hits**.
- Controlled deterministic packager smoke after the Iteration 14.7 changes: **PASS**. Two archives from identical source and `SOURCE_DATE_EPOCH` produced byte-identical SHA-256 `f8a7070250e5896fb88c38830fda64aa62c6ce3787d3202aeda5f35ebd5ad39e`; `unzip -t` passed; `RELEASE_MANIFEST.json` was present; dependency/runtime/cache/secret exclusions were clean. This is release-engine verification using a controlled manifest fixture, **not** evidence of a real Vite production build.
- An additional attempt was made to bootstrap missing PHP/Composer runtime prerequisites through direct package/binary retrieval after shell package repositories remained unavailable. The runner's artifact-download path also rejected those external binary artifacts, so this did not create runtime evidence and no gate status was upgraded.

Current runtime preflight remains environment-blocked:

```text
PASS  PHP 8.4.23
PASS  Node 22.16.0
PASS  npm 10.9.2
FAIL  Composer CLI
FAIL  PHP mbstring
FAIL  PHP dom
FAIL  PHP xml
FAIL  PHP xmlwriter
FAIL  PHP pdo_mysql
FAIL  MySQL 8 runtime/service
```

Current release state after Iteration 14.7:

```text
RISK/ACTION COMPLETION INVARIANT             HARDENED
MANAGEMENT REVIEW LIFECYCLE                  HARDENED
KPI MEASUREMENT CONFIG SNAPSHOT              HARDENED
RISK/NOTIFICATION MONITORING CONCURRENCY      HARDENED
ADMIN AUTHORIZATION TOCTOU                    HARDENED
MANUAL KPI CSV TRANSACTION/LOCKING            HARDENED
INTEGRATION AUTHORITY AT PUBLISH              HARDENED
INTEGRATION MUTATION AUTHORIZATION TOCTOU     HARDENED
FRONTEND/API CONTRACT RELEASE GATE            PASS
STATIC SOURCE VALIDATION                      PASS
DETERMINISTIC PACKAGER ENGINE                 PASS
REAL MYSQL migrate:fresh                      PENDING
FULL PHPUnit ON MYSQL                         PENDING
PINT RUNTIME                                  PENDING
REAL CLEAN npm ci + VITE BUILD                PENDING
PRODUCTION RELEASE-CHECK WITH REAL DB/BUILD   PENDING
FULL BROWSER E2E                              PENDING
FINAL PRODUCTION PASS                         NOT DECLARED
FINAL ZIP                                     NOT CREATED
```


## Iteration 14.8 — Historical evidence consistency and deployment/release closure

Status: **source hardening complete for this iteration; mandatory runtime gates remain environment-blocked**.

Completed in this iteration:

- Reworked Certification analytics to be genuinely as-of: future decisions, pass/fail outcomes, completed status, certificate issuance, and SLA evidence no longer rewrite an older period.
- Redacted future payment/reversal details from historical Finance invoice rows.
- Redacted future IT resolution timestamp, resolver, root cause, and resolution summary from historical incident rows.
- Strengthened Governance historical reconstruction and terminal lifecycle rules for Finding/CAPA/Appeal.
- Redacted future Risk/Action resolution evidence.
- Added historical rollback for Management Review and Management Review Item fields using audit-log `before` states for changes after the report cutoff; future approval/closure timestamps are also redacted.
- Added direct regression coverage for Certification, Finance, IT, Governance, Risk/Action, and Management Review cutoff scenarios.
- Production deployment now performs runtime + production configuration/build preflight before entering maintenance mode.
- Production readiness rejects placeholder database credentials and the template production URL.
- Aligned the Composer platform contract to `PHP ^8.4.1` and recalculated `composer.lock` content-hash with Composer's own relevant-key hashing algorithm; locked package versions were not changed.

Latest source/static evidence:

```text
PHP lint                     150 files / 0 errors
Migrations                   29
Created tables               41
Duplicate table create       0
Models                       34
Discovered test methods      101
Laravel API routes           79
Frontend API contracts       78
Missing API contracts        0
Fixed demo credential hits   0
```

Controlled packager reproducibility smoke remains PASS. This is packager-engine evidence only and not a substitute for a real Vite production build.

Open mandatory gates remain: clean Composer install on a complete PHP runtime, clean npm install/build, MySQL 8 `migrate:fresh`, full PHPUnit on MySQL, Pint, production readiness with real DB/build, browser E2E, and only then deterministic FINAL packaging.

## Iteration 14.9 — Evidence integrity, immutable ledgers, integration lifecycle parity, and exact MySQL target

Status: **source hardening complete for this iteration; mandatory production runtime gates remain pending on an environment with Composer, required PHP extensions, MySQL 8.x, and clean frontend dependency access**.

Implemented/fixed:

- Evidence Pack ZIP generation is now deterministic for a given immutable report snapshot. `SimpleZipWriter` uses a fixed snapshot-derived timestamp, rejects path traversal/duplicate entries, and no longer embeds export-time clock variance.
- `manifest.json` inside each Evidence Pack now records `name`, byte length, and SHA-256 for every payload file (`report.json`, `report.csv`, printable HTML, KPI snapshot, provenance, and table CSVs). Re-exporting the same snapshot can therefore be verified byte-for-byte and file-by-file.
- `FinancialRecord`, `CertificateIssuance`, and `ReportSnapshot` are enforced as immutable evidence ledgers. Model-layer update/delete guards are backed by MySQL `BEFORE UPDATE/DELETE` triggers in the new release-hardening migration; SQLite/local mode retains model guards while skipping MySQL-specific triggers.
- Integration publishing for `certification_batches` now re-applies operational lifecycle invariants after the target row is locked. Integration can no longer regress certification lifecycle, make `passed` smaller than issued certificates, move decision chronology after issuance, or override system-derived certificate due dates.
- Integration publishing for `it_incidents` now re-applies `open -> investigating -> resolved` forward-only lifecycle, acknowledgement/resolution chronology, and resolution-evidence requirements after row locking.
- Staging validation was strengthened for the same certification/IT chronology constraints so invalid input is rejected earlier, while publish-time checks remain the authority against staging-to-publish TOCTOU.
- MySQL runtime verification now rejects MariaDB and other compatible-but-non-target engines. Both CI runtime assertion and production readiness require an actual MySQL server with major version 8.x, matching the documented production baseline.
- Regression coverage was added for immutable ledgers, deterministic Evidence Pack behavior/file hashes, integration certification lifecycle regression, system-derived certificate due date, and IT incident lifecycle regression.

Verification performed after all Iteration 14.9 source changes:

```text
PHP source lint                 = PASS — 152 files / 0 syntax errors
Migration files                 = 30
Created tables                  = 41
Models                          = 34
Discovered test methods         = 108 (discovery only)
Laravel API routes (static)     = 79
Frontend API contracts          = 78
Missing frontend contracts      = 0
Historical fixed demo password  = 0 source hits
JSON/XML/YAML parse             = PASS
Shell/Python script parse       = PASS
Runtime preflight               = FAIL only on unavailable runner prerequisites
```

Controlled packager-engine reproducibility smoke after Iteration 14.9:

```text
SHA-256 A/B = identical in the controlled verification run
byte-identical               = PASS
unzip integrity              = PASS
RELEASE_MANIFEST.json        = PRESENT
forbidden dependency/env/log = 0 entries
```

The controlled packager smoke uses an intentionally synthetic Vite manifest/asset to verify the release engine only. It is **not** evidence that a real `npm run build` passed.

The current runner still lacks Composer CLI, PHP `mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`, and a MySQL 8 server. A clean `npm ci` attempt also did not complete within the runner network window and created only a partial dependency tree, which was removed. Therefore none of the following are promoted to PASS: clean Composer install, real MySQL `migrate:fresh`, full PHPUnit-on-MySQL, Pint, real Vite build, production release-check with live DB/build, or browser E2E.

## Iteration 14.10 — Historical overview, certificate issuance identity, and fail-closed deployment

Status: **source hardening complete for this iteration; mandatory production runtime gates remain pending**.

Completed in this iteration:

- Historical executive/department overview no longer counts ActionItems created after the requested cutoff and no longer treats actions completed after the cutoff as already completed in the older period. Overdue action counts now compare against the report cutoff instead of the current date.
- `overview.last_updated_at` is now derived only from source events visible at or before the report cutoff, preventing future mutation timestamps from leaking into older reports.
- `certificate_issuances.reference` now has a database unique invariant when populated, and the API validates reference uniqueness before issuance creation.
- Certification batches with issuance evidence are protected from parent deletion at the Eloquent layer and by a MySQL parent-delete trigger, preventing cascade removal of immutable issuance evidence.
- Production deployment now fails closed after maintenance mode. A migration/cache/final-readiness failure leaves NADI in maintenance mode rather than automatically bringing traffic back onto a possibly partial schema/release.
- Clean `npm ci` was retried. The runner again did not complete dependency installation; the partial `node_modules` tree was removed and is not used as build evidence.
- The locked Composer package set was compared against the previously available dependency cache: 114/114 package name+version pairs match. That cache was used only to boot Laravel and verify route inventory, not as clean-install/test evidence.

Latest source/static evidence:

```text
Laravel Framework               13.31.0 (cache-assisted boot only)
Routes                           83 total / 79 API
PHP source files                 156
PHP syntax errors                0
Migration files                  31
Created tables                   41
Duplicate table creates          0
Models                           34
Discovered test methods          110
Frontend API contracts           78
Missing backend contracts        0
Historical fixed demo password   0 source hits
Composer manifest/lock freshness PASS (Composer-compatible PHP hash)
```

Open mandatory gates remain unchanged: clean Composer install on a complete PHP runtime, clean npm install/build, real MySQL 8 `migrate:fresh`, full PHPUnit on MySQL, Pint, production release-check with live DB/build, browser E2E, and only then deterministic FINAL packaging.

Controlled release-engine smoke after all Iteration 14.10 changes:

```text
SHA-256 A = c7ee9861f7380be3c9f1dd8e1d30ade102f66ac7ed940b2c2e720dc445844eaa
SHA-256 B = c7ee9861f7380be3c9f1dd8e1d30ade102f66ac7ed940b2c2e720dc445844eaa
byte-identical = PASS
unzip integrity = PASS
RELEASE_MANIFEST.json = PRESENT
runtime .env/vendor/node_modules/cache/db/log entries = 0
```

`.env.example` and `.env.production.example` remain intentionally present as safe configuration templates. This smoke uses a synthetic Vite asset and is release-engine evidence only, not a real frontend production build.

## Iteration 14.11 — Historical correction stability, read-only decision surfaces, import auditability, and master-data race closure

Status: **source hardening complete for this iteration; mandatory production runtime gates remain pending**.

Completed in this iteration:

- Decision Center GET/index no longer calls risk synchronization. Reading `/api/decisions` is side-effect free; risk detection/auto-resolution remains owned by the scheduled `nadi:monitor-risks` workflow.
- Management Review creation now locks and revalidates the current actor permission plus selected RiskSignal scope inside the transaction before creating the review/items.
- Manual KPI CSV import now rejects identical source content by SHA/source identity, locks all relevant KPI definitions before resolving effective configuration, and records per-measurement create/update audit entries with before/after values.
- Certification historical analytics now rolls back post-cutoff batch corrections from AuditLog `before` values for passed/failed/status/lifecycle timestamps. Candidate expansion also includes batches whose decision/due dates were corrected out of the historical SQL window, preventing old CERT-PASS/CERT-SLA cohorts from disappearing before as-of reconstruction.
- Integration publishing for CertificationBatch and IT Incident writes before/after audit records, closing a historical reconstruction blind spot for updates arriving through integration onboarding.
- Interactive certification batch creation now locks referenced Scheme/TUK/Assessor rows and re-checks active/archive state inside the same transaction. Integration certification publishing applies the same master-data lock/recheck rule.
- Release verification now runs runtime preflight, API-contract audit, actual MySQL runtime assertion, PHPUnit with `NADI_REQUIRE_TEST_DB=mysql`, Pint, production release-check, and packaging/source hygiene in a fail-fast sequence.
- CI deterministic package smoke now creates two archives from the same build/epoch and compares them byte-for-byte rather than checking only a single package.
- Regression coverage was added for Decision GET side-effect freedom, Management Review scope/permission revalidation, identical KPI CSV rejection + audited correction, historical certification result corrections, and historical decision-date correction moved out of period.

Latest clean-source/static evidence after removing temporary dependency caches:

```text
PHP source files                156
PHP syntax errors               0
Migration files                 31
Created tables                  41
Duplicate table creates         0
Models                          34
Discovered test methods         114 (discovery only)
Laravel API routes (static)     79
Frontend API contracts          78
Missing frontend contracts      0
Historical fixed demo password  0 source hits
JSON/XML/YAML parse              PASS
Shell/Python syntax              PASS
Composer manifest/lock freshness PASS
```

Current runtime preflight remains fail-closed only on unavailable runner prerequisites: Composer CLI, PHP `mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`, and MySQL 8. PHP 8.4.23, Node 22.16.0, npm 10.9.2, and the other required PHP core extensions are present. A clean frontend install attempt in this iteration again failed to complete in the available runner/network environment and no partial `node_modules` tree is retained.

Mandatory runtime gates therefore remain open: clean Composer install, clean npm/Vite production build, MySQL 8 `migrate:fresh`, full PHPUnit on MySQL, Pint, production release-check with live DB/build, HTTP/browser E2E, and only then FINAL packaging.

Controlled Iteration 14.11 release-engine smoke after the final source/docs changes:

```text
SHA-256 A = d8bca871ad137b2c626016dce8d566f2498c60bc019b2676a53d706bf94261b3
SHA-256 B = d8bca871ad137b2c626016dce8d566f2498c60bc019b2676a53d706bf94261b3
byte-identical = PASS
unzip integrity = PASS
RELEASE_MANIFEST.json = PRESENT
runtime .env/vendor/node_modules/__pycache__/DB/log entries = 0
```

This controlled smoke uses a synthetic Vite manifest/asset solely to validate packager reproducibility and hygiene. It is not evidence that the real frontend production build passed.

## Iteration 14.12 — Evidence boundary, provenance immutability, authorization TOCTOU closure, and source closure

Status: **source closure complete for this iteration; remaining release work is runtime-only validation on an environment with the documented production prerequisites**.

Completed in this iteration:

- `AuditLog` and `AuthenticationEvent` are append-only at the Eloquent layer and, on MySQL, protected by `BEFORE UPDATE/DELETE` triggers. Historical reconstruction can no longer silently lose its source audit evidence through ordinary model/direct-SQL mutation.
- `DataImportBatch` provenance identity (`reference`, source, dataset identity, file name, SHA-256, import time, creator) is immutable while reconciliation/lifecycle fields remain mutable. Import batches themselves cannot be hard-deleted.
- Data sources, compliance findings with CAPA evidence, and certification batches with issuance/appeal evidence receive parent-delete protection so cascade relationships cannot erase provenance/governance evidence.
- User identities, RiskSignals, ActionItems, Management Reviews, and Management Review Items are now hard-delete protected. The intended lifecycle is deactivate/resolve/complete/close, preserving attribution and decision evidence.
- Report generation locks/revalidates the current actor and `reports.view` permission inside the commit transaction, closing permission-revocation TOCTOU.
- Master-data mutations and custom KPI measurement writes lock/revalidate the actor before mutation; custom KPI measurements now retain explicit before/after audit evidence on updates.
- Report `source_manifest` is report-scoped rather than global. Finance reports can no longer expose Certification/IT source filenames, hashes, or import references simply because those sources exist in the system. Executive reports retain cross-domain provenance; department reports are scoped to their operating domain plus custom-KPI batches actually referenced by their measurements.
- Legacy KPI CSV batch creation now locks/revalidates the actor and selected DataSource before provenance is created, rejects inactive sources, and uses immutable `created_by` as the terminal audit actor even if session permissions/status later change.
- A local extension-build fallback was investigated. The runner has GCC/make and runtime libraries but lacks `phpize`, `php-config`, PHP headers, the required extension modules, and MySQL server binaries; therefore missing PHP/MySQL runtime gates cannot be truthfully manufactured locally.
- Clean `npm ci` was retried. It again failed to complete within the runner/network window and created a partial dependency tree; the partial `node_modules` directory was removed and is not retained as build evidence.

Latest clean-source evidence after the Iteration 14.12 changes:

```text
PHP source files                 155
PHP syntax errors                0
Migration files                  33
Schema::create declarations      41
Unique created tables            41
Models                           34
Test methods discovered          119 (discovery only)
Laravel API routes (static)      79
Frontend API contracts           78
Missing frontend contracts       0
Historical demo password hits    0
Composer manifest/lock           FRESH
```

A cache-assisted Laravel boot remains limited to structural/route evidence only. Clean Composer install, MySQL 8 `migrate:fresh`, full PHPUnit-on-MySQL, Pint, real Vite production build, production release-check with real DB/build, and browser E2E remain mandatory and pending. No FINAL production ZIP is produced by this iteration.

Controlled Iteration 14.12 release-engine smoke after the source/document closure:

```text
SHA-256 A = 8e5d84ab8c58c1a353be3a19988daa8af70a234979c6ae48416019a08e11b2a0
SHA-256 B = 8e5d84ab8c58c1a353be3a19988daa8af70a234979c6ae48416019a08e11b2a0
byte-identical = PASS
unzip integrity = PASS
RELEASE_MANIFEST.json = PRESENT
forbidden runtime/dependency/cache entries = 0
```

This smoke used a synthetic Vite manifest/asset only to verify release-engine determinism. The fixture and smoke output were removed immediately afterward and are not part of the source checkpoint or production evidence.

## Iteration 15.1 — Mandatory runtime validation entry, release-gate safety, and artifact integrity

Status: **runtime validation started; local runner prerequisites remain externally blocked, while executable release/deployment gates were hardened and verified.**

Completed in this iteration:

- Re-entered from the clean Iteration 14.12 checkpoint; no prior source hardening was discarded.
- Verified the original vendor cache against the current `composer.lock`: 114/114 package name+version pairs match. The cache was used only for diagnostic Laravel boot (`Laravel 13.31.0`, 83 routes / 79 API), never as clean Composer-install/PHPUnit evidence, and was removed before checkpointing.
- Diagnostic PHPUnit bypass confirmed the missing extensions are hard blockers: after temporarily bypassing PHPUnit's extension preflight, PHPUnit itself immediately required `DOMDocument`. No fake test PASS is possible without `dom`/`mbstring`/`xmlwriter`.
- `scripts/verify-release.sh` now includes the mandatory clean MySQL `migrate:fresh` gate before PHPUnit. A new `scripts/assert_verification_database.php` requires explicit `NADI_VERIFY_DESTRUCTIVE_DATABASE` acknowledgement matching `DB_DATABASE` and refuses destructive verification unless the database name is visibly test/CI/verify scoped.
- Production APP_KEY readiness now validates the decoded AES-256-CBC key length and rejects obvious low-entropy/placeholder keys. CI generates a fresh random 32-byte APP_KEY instead of using a committed dummy key.
- Production readiness now requires durable database-backed session, cache, and queue configuration (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`).
- Build-time and runtime-only preflight are separated. Production application servers no longer require Node/npm when deploying an artifact that already contains `public/build`; release/build hosts still require Node/npm.
- Production deployment preflight is moved before Composer install and before maintenance mode where possible.
- Added `scripts/verify_release_manifest.php`. Deployment now refuses an extracted artifact without `RELEASE_MANIFEST.json` and verifies each listed file's size and SHA-256 before dependency installation or database migration.
- Manifest verification was executed against a controlled packaged release: intact artifact PASS; after tampering `README.md`, verification failed on the changed file before deployment activity.
- Vite manifest validation now also rejects missing `imports`/`dynamicImports` manifest entries in addition to missing direct JS/CSS/assets. A negative packaging test confirmed the invalid manifest is rejected.
- GitHub Actions release workflow was aligned with current checkout major (`actions/checkout@v6`) and production-like readiness/smoke steps now exercise database cache/queue settings.
- Read-only search of the connected GitHub installation found no NADI/LSP-MIGAS repository, so no source was uploaded and no external repository was created without explicit authorization.

Current local runtime evidence:

```text
PHP 8.4.23                     PASS
Node 22.16.0                   PASS
npm 10.9.2                     PASS
Composer CLI                   MISSING
PHP mbstring                   MISSING
PHP dom                        MISSING
PHP xml                        MISSING
PHP xmlwriter                  MISSING
PHP pdo_mysql                  MISSING
MySQL 8 server                 UNAVAILABLE
npm registry DNS               EAI_AGAIN
```

Clean `npm ci` was retried and failed on registry DNS (`EAI_AGAIN`); the partial `node_modules` tree was removed. Therefore real Vite build remains pending.

Static/executable release evidence after the iteration changes:

```text
PHP syntax sweep               PASS
Frontend/API contract          79 routes / 78 calls / 0 missing
JSON/XML/YAML parsing          PASS
Shell/Python syntax            PASS
APP_KEY low-entropy rejection  PASS (direct Laravel service diagnostic)
DB cache/queue readiness       PASS/FAIL behavior verified directly
Release manifest intact        PASS
Release manifest tamper        FAIL as required
Invalid Vite import manifest   FAIL as required
```

Mandatory production gates still open: clean Composer install, real `npm ci && npm run build`, MySQL 8 `migrate:fresh`, full PHPUnit on MySQL, Pint, live production release-check, HTTP/browser E2E, then deterministic FINAL packaging. No final release ZIP is produced by Iteration 15.1.

Final controlled packager smoke after all Iteration 15.1 source/document changes:

```text
SHA-256 A/B = a33e2397584fd8165063c179dfe6412c2f92407c1c6eae16b9cca0f6fd5c7d1c
byte-identical = PASS
unzip integrity = PASS
```

The smoke used a synthetic Vite manifest/assets solely to verify deterministic packaging after the 15.1 changes. The fixture was removed before checkpointing and this digest is not a final release checksum.


## Iteration 15.4D — Local/CI runner convergence and execution readiness

Production release verification now has one authoritative implementation: `scripts/final-gate.sh`. The legacy `scripts/verify-release.sh` and macOS `--release-gate` path are wrappers only; the macOS SQLite demo path is explicitly non-production. `scripts/release-gate-doctor.sh` adds early environment/tooling diagnostics and cross-platform checksum readiness, while `docs/RELEASE_GATE_EXECUTION.md` records the environment contract, gate order, and failure/retry semantics.

Controlled regression proved direct/manual/macOS release-gate entrypoints terminate through the same authoritative phase when prerequisites are missing. Current runner blockers remain environmental, so no executable production gate has been promoted to PASS.

## Iteration 15.15 — Final source freeze and external runtime execution handoff

The release candidate now has an explicit source-freeze contract. The authoritative final gate re-verifies the audited source and freeze policy before environment/bootstrap work. External execution handoff tooling binds an exact checkpoint ZIP to the source fingerprint, release contracts and explicit GitHub repository identity; any subsequent source edit requires a new iteration/refreeze.

This iteration does not create runtime PASS evidence. A real NADI GitHub repository identity is still unavailable from the checkpoint/current connected GitHub environment, so real external execution and FINAL authorization remain pending.
