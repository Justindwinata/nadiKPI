# FINAL_RELEASE_NOTES.md

# NADI — LSP MIGAS KPI Monitoring & Decision Intelligence System
## Final Release Notes / Source Release Candidate Handoff

**Project:** NADI — KPI Monitoring, Operational Intelligence, Governance & Decision Support System  
**Target Organization:** LSP Migas  
**Primary Stack:** Laravel 13 + React 19 + MySQL 8.x  
**Document Date:** 2 October 2026  
**Current Release State:** **SOURCE RELEASE CANDIDATE — SOURCE CLOSURE COMPLETE THROUGH ITERATION 14.12, FINAL RUNTIME VALIDATION PENDING**  
**Last Source-Hardening Iteration:** **Iteration 14.12 — Evidence Boundary & Source Closure**

---

## 1. Executive Summary

NADI dikembangkan dari prototype dashboard KPI menjadi sistem operasional lintas divisi yang mengintegrasikan:

- KPI eksekutif;
- operasional sertifikasi;
- keuangan dan piutang;
- reliability & data quality IT;
- governance, compliance, CAPA, dan banding;
- provenance dan integrasi data nyata;
- user management dan granular RBAC;
- risk signal dan decision workflow;
- notification center dan scheduled monitoring;
- management review;
- reporting dan management evidence pack;
- release engineering serta production hardening.

Arsitektur akhir tidak lagi mengandalkan KPI manual untuk indikator inti. Sembilan KPI default berikut dihitung langsung dari operational source of truth:

### Sertifikasi
- `CERT-VOLUME`
- `CERT-PASS`
- `CERT-SLA`

### Finance
- `FIN-REV`
- `FIN-MARGIN`
- `FIN-BUDGET`

### IT
- `IT-UPTIME`
- `IT-MTTR`
- `IT-DATA`

KPI inti tersebut tidak dapat dioverride melalui form manual maupun CSV.

---

## 2. Important Release Status

### Completed

```text
SOURCE IMPLEMENTATION
✅ COMPLETE

STATIC / STRUCTURAL AUDIT
✅ PASS

CROSS-MODULE REMEDIATION
✅ PASS

SECURITY / RBAC HARDENING
✅ PASS

REPORTING / EVIDENCE EXPORT
✅ PASS

RELEASE PACKAGING GUARDS
✅ PASS

SOURCE RELEASE CANDIDATE
✅ READY
```

### Still Pending Before Production FINAL PASS

Tiga release gate berikut **belum boleh dianggap PASS**, karena environment ChatGPT yang digunakan selama pengembangan tidak memiliki dependency/runtime yang diperlukan:

```text
PHPUnit runtime
⚠ BLOCKED BY ENVIRONMENT

Real MySQL runtime / migrations / transaction tests
⚠ BLOCKED BY ENVIRONMENT

Clean npm ci + Vite production build
⚠ BLOCKED BY ENVIRONMENT
```

Alasan utama:
- PHP CLI environment tidak mempunyai `dom`, `mbstring`, `xmlwriter`, dan `pdo_mysql`.
- MySQL/MariaDB runtime tidak tersedia.
- Network ke npm registry gagal (`EAI_AGAIN`).
- Cache npm lokal tidak lengkap (`ENOTCACHED`).
- `node_modules` dari ZIP awal berasal dari platform lain sehingga tidak dapat dijadikan bukti build Linux production.

**PENTING:** jangan mengubah status menjadi `FINAL PASS` sampai ketiga gate tersebut benar-benar dieksekusi dan lulus.

---

## 3. Iteration 1 — P0 Correctness

### Completed
- Memperbaiki mismatch status TUK.
- Mengaktifkan `warning_threshold` KPI.
- Mendukung KPI higher-is-better dan lower-is-better.
- Menambahkan regression tests untuk threshold dan TUK.

---

## 4. Iteration 2 — Certification Operational Truth

Lifecycle:

```text
PLANNED
→ DOCUMENT REVIEW
→ ASSESSMENT
→ RECORD RESULT
→ DECISION
→ CERTIFICATE ISSUANCE
→ COMPLETED
```

Implemented:
- kompeten / belum kompeten / pending;
- validasi hasil asesmen;
- assessment completion timestamp;
- certification decision timestamp;
- certificate due date otomatis;
- SLA penerbitan sertifikat;
- partial certificate issuance;
- certificate issuance ledger;
- certificate backlog;
- overdue certificate;
- certificate issuance audit;
- batch completion guard.

KPI:
```text
Certification operational data
        ↓
KPI engine
        ↓
CERT-VOLUME
CERT-PASS
CERT-SLA
```

Manual/CSV override ditolak.

---

## 5. Iteration 3 — Finance Operational Truth

Implemented:
- immutable financial ledger;
- revenue, expense, budget, reversal;
- invoice lifecycle;
- auto revenue posting;
- payment ledger;
- partial payment;
- outstanding balance;
- overpayment rejection;
- payment reversal;
- receivable aging;
- invoice void via reversal, bukan delete.

Derived KPI:
```text
FIN-REV
FIN-MARGIN
FIN-BUDGET
```

Semua berasal dari financial ledger.

---

## 6. Iteration 4 — IT Reliability & Data Quality

Implemented:
- incident lifecycle `OPEN → INVESTIGATING → RESOLVED`;
- resolution summary;
- root cause;
- overlap-safe downtime;
- cross-month clipping;
- proper MTTR cohort;
- data-quality ledger;
- weighted data-quality calculation.

Derived KPI:
- `IT-UPTIME`
- `IT-MTTR`
- `IT-DATA`

---

## 7. Iteration 5 — Governance, Compliance & Provenance

Implemented:
- compliance finding register;
- CAPA;
- evidence-required CAPA completion;
- finding closure guard;
- certification appeals;
- compliance obligation register;
- scheme/TUK/assessor expiry monitoring;
- data-source registry;
- authority rank;
- CSV import provenance;
- SHA-256 import fingerprint;
- reconciliation;
- governance audit-log viewer.

---

## 8. Iteration 6 — Identity, RBAC & Security

Roles:
```text
director
department_head
analyst
viewer
```

Granular permission examples:
```text
certification.view
certification.manage
certification.issue
finance.view
finance.ledger.manage
finance.invoices.manage
finance.payments.manage
finance.reverse
it.view
it.incidents.manage
it.data_quality.manage
governance.view
governance.findings.manage
governance.appeals.manage
governance.registry.manage
governance.provenance.manage
governance.audit.view
integrations.view
integrations.manage
kpi.catalog.view
kpi.catalog.manage
decisions.view
decisions.manage
decisions.review
reports.view
reports.export
users.manage
```

Security:
- forced password change;
- strong password policy;
- session regeneration/revocation;
- login throttling;
- auth event logs;
- user activation/deactivation;
- admin password reset;
- permission override with reason;
- segregation of duties.

---

## 9. Iteration 7 — Real Data Onboarding

Integration pipeline:

```text
Source / CSV
    ↓
Source Registry
    ↓
SHA-256
    ↓
Staging
    ↓
Column Mapping
    ↓
Validation
    ↓
Dependency Validation
    ↓
Idempotency
    ↓
Controlled Publish
    ↓
Operational Ledger
```

Supported dataset contracts:
- certification schemes;
- TUK;
- assessors;
- certification batches;
- certificate issuances;
- finance invoices;
- finance payments;
- financial records;
- IT services;
- IT incidents;
- data-quality runs.

Idempotency:
```text
not found → INSERT
same external key + same hash → SKIP
changed + mutable → UPDATE
changed + immutable → REJECT
```

Authority-rank enforcement implemented.

---

## 10. Iteration 8 — KPI Catalog & Master Lifecycle

KPI Catalog mendukung:
- target;
- warning threshold;
- weight;
- owner;
- effective period;
- activation/deactivation;
- change reason;
- version history.

Master lifecycle:
```text
CREATE
READ
UPDATE
ARCHIVE
RESTORE
```

Archive bukan delete dan mempunyai dependency guard.

---

## 11. Iteration 9 — Decision Workflow & Management Review

Risk sources:
- KPI exception;
- overdue CAPA/finding;
- overdue receivable;
- certificate backlog;
- IT incidents;
- compliance expiry.

Risk flow:
```text
Risk Signal
→ Acknowledge
→ Action
→ Escalation
→ Resolution
→ Management Review
```

Action completion wajib memiliki:
- resolution note;
- evidence.

Management Review:
```text
DRAFT
→ IN REVIEW
→ APPROVED
→ CLOSED
```

---

## 12. Iteration 10 — Notification & Scheduled Monitoring

Command:
```bash
php artisan nadi:monitor-risks
```

Scheduler:
```text
every minute
```

Implemented:
- notification inbox;
- unread/read;
- acknowledge notification;
- critical/high/normal severity;
- auto escalation;
- overdue action reminder;
- management review reminder;
- recipient isolation;
- notification retention;
- SSE realtime delivery;
- `Last-Event-ID`;
- fallback refresh.

---

## 13. Iteration 11 — Reporting & Evidence Pack

Report types:
- Executive KPI & Management Report;
- Department Performance Report;
- Certification Operational Report;
- Finance Performance Report;
- IT Reliability & Data Quality Report;
- Governance & Compliance Report;
- Risk & Action Register;
- Management Review Report.

Immutable report snapshots menyimpan:
- report type;
- period;
- department;
- generated by;
- generated at;
- payload;
- source manifest;
- SHA-256;
- schema version.

Evidence Pack ZIP dapat berisi:
```text
manifest.json
report.json
report.csv
printable-report.html
kpi_snapshot.csv
provenance.csv
tables/*.csv
```

CSV formula injection protection diterapkan.

---

## 14. Iteration 12 — Final Release Engineering

Implemented:
- `/up` liveness;
- `/api/health/ready` readiness;
- release checker;
- production-safe config;
- generated-cache cleanup;
- prototype seeder protection;
- CI release gate;
- deterministic release packager;
- release manifest with SHA-256;
- production deployment scripts;
- backup/restore docs;
- accessibility source hardening;
- stale external font removal;
- safe demo mode default.

Commands:
```bash
php artisan nadi:release-check
php artisan nadi:release-check --production
```

Packager menolak release bila:
```text
public/build/manifest.json
```
belum ada.

---

## 15. Iteration 13 — Final RC Validation & Remediation

Audit lintas-modul menemukan dan memperbaiki beberapa defect penting.

### 15.1 Current-period future transaction bug
KPI bulan berjalan sekarang hanya memakai:
```text
transaction_time <= report_as_of
```

### 15.2 Historical Finance as-of semantics
Payment yang direversal setelah periode laporan tetap dianggap aktif pada snapshot sebelum tanggal reversal.

### 15.3 IT monitoring window
Ditambahkan:
```text
monitoring_started_at
```
untuk mencegah denominator uptime salah.

### 15.4 Finance reversal concurrency
Ditambahkan:
- `lockForUpdate()`;
- unique invariant terhadap `reversal_of_id`.

### 15.5 Payment reversal locking
Payment dan invoice dikunci selama reversal.

### 15.6 Invoice void race condition
Semua invariant void dicek setelah invoice row di-lock.

### 15.7 Reporting object-level authorization
Cross-department snapshot access diperketat.

### 15.8 Historical Certification backlog
Certificate issuance setelah report end tidak mengubah backlog historis.

### 15.9 Historical Risk & Action
Ditambahkan `status_as_of`.

### 15.10 Historical Governance
As-of semantics diterapkan pada finding, CAPA, dan appeal.

### 15.11 Demo credential source hardening
- React source tidak lagi menyimpan demo credential.
- Server tidak memiliki fallback demo password.
- `NADI_DEMO_PASSWORD` wajib eksplisit pada demo environment.
- `/api/demo-access` hanya aktif pada demo mode.

---

## 16. Latest Recorded Source Audit

```text
Migration files        = 28
Created tables         = 41
Duplicate table create = 0

Models                  = 34
Invalid fillable        = 0
```

Latest PHP lint:
```text
PHP source files = 146
Syntax errors    = 0
```

Frontend parser:
```text
resources/js/app.jsx_ERRORS = 0
vite.config.js_ERRORS       = 0
```

Latest route inventory:
```text
API routes              = 79
Forced-password routes  = 73
Permission-gated routes = 59
```

---

## 17. Production Configuration Baseline

Recommended production environment:

```env
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=mysql

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

VITE_DEMO_MODE=false
NADI_ALLOW_DEMO_SEED=false
```

Production administrator:
```bash
php artisan nadi:create-admin admin@company.tld --name="Administrator NADI"
```

Do not run prototype seeders in production.

---

## 18. Final Runtime Gate Closure — Mandatory

Before `FINAL PASS`, execute all of the following.

### 18.1 Environment
Required:
- compatible PHP;
- Composer;
- `dom`;
- `mbstring`;
- `xml`;
- `xmlwriter`;
- `pdo_mysql`;
- MySQL 8.x;
- Node/npm compatible with lockfile.

### 18.2 Backend clean install
```bash
composer install
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

### 18.3 Frontend clean install
Do not reuse old `node_modules`.

```bash
rm -rf node_modules
npm ci
npm run build
```

Required output:
```text
public/build/manifest.json
```

### 18.4 MySQL migration validation
On clean MySQL test database:

```bash
php artisan migrate:fresh
```

Validate:
- all migrations;
- foreign keys;
- unique reversal invariant;
- certification lifecycle;
- finance concurrency;
- IT monitoring lifecycle;
- reporting snapshots.

### 18.5 Full tests
```bash
php artisan test
```

Do not ignore release-critical failures.

### 18.6 Style
```bash
vendor/bin/pint --test
```

### 18.7 Production readiness
```bash
php artisan nadi:release-check --production
```

Required result:
```text
PASS
```

### 18.8 HTTP smoke
```text
GET /up
→ 200

GET /api/health/ready
→ 200
```

### 18.9 Browser smoke
Verify at minimum:
1. login;
2. forced password change;
3. director dashboard;
4. certification lifecycle;
5. finance invoice/payment/reversal;
6. IT incident lifecycle;
7. governance finding/CAPA;
8. integration staging/publish;
9. risk/action workflow;
10. notifications/SSE;
11. report generation/export;
12. user permission override;
13. archive/restore master;
14. logout/session behavior.

---

## 19. Production Release Packaging

Only after:
```text
npm run build PASS
php artisan test PASS
MySQL migrations PASS
nadi:release-check --production PASS
```

run:

```bash
python3 scripts/package_release.py --version=<FINAL_VERSION>
```

Then:

```bash
unzip -t <release>.zip
```

Verify:
- no `.env`;
- no demo DB;
- no `node_modules`;
- no runtime logs;
- no stale cache;
- `RELEASE_MANIFEST.json` exists;
- release SHA-256 is recorded.

---

## 20. Security Checklist Before Go-Live

Confirm:
- HTTPS active;
- `APP_DEBUG=false`;
- `VITE_DEMO_MODE=false`;
- `NADI_ALLOW_DEMO_SEED=false`;
- no demo credentials;
- secure session cookie;
- least-privilege DB user;
- strong `APP_KEY`;
- login throttling enabled;
- scheduler active;
- audit logs operational;
- production permissions reviewed;
- temporary passwords replaced;
- backup tested;
- readiness returns 200.

---

## 21. What Must NOT Be Done in the New Room

Do not:
- restart the project;
- make a new repository;
- replace Laravel/React stack;
- revert operational source-of-truth architecture;
- reintroduce manually editable core KPI;
- delete finance audit records for correction;
- bypass authority rank;
- remove provenance;
- remove immutable report snapshots;
- claim PHPUnit/MySQL/Vite PASS without execution;
- package final production ZIP without successful Vite build;
- use prototype seed data as real company data.

---

## 22. Next Work

Next task name:

# FINAL RUNTIME GATE CLOSURE

Priority:
```text
P0
1. Clean npm ci
2. npm run build
3. Clean MySQL 8 database
4. migrate:fresh
5. Full php artisan test
6. Fix failures
7. Pint
8. production release-check
9. browser/runtime smoke
10. deterministic final package
```

No major business features should be added unless required to fix an actual runtime defect.

---

## 23. Final State Summary

```text
Iteration 1  — Correctness                         ✅
Iteration 2  — Certification Operational Truth     ✅
Iteration 3  — Finance Operational Truth           ✅
Iteration 4  — IT Reliability & Data Quality       ✅
Iteration 5  — Governance / Compliance             ✅
Iteration 6  — Identity / RBAC / Security          ✅
Iteration 7  — Real Data Onboarding                ✅
Iteration 8  — KPI Catalog + Master Lifecycle      ✅
Iteration 9  — Decision Workflow                   ✅
Iteration 10 — Notification + Realtime Monitoring  ✅
Iteration 11 — Reporting & Evidence Pack           ✅
Iteration 12 — Final Release Engineering            ✅
Iteration 13 — Final RC Validation & Remediation    ✅

Iterations 14–16:
NOT VERIFIED AS COMPLETED IN THIS DOCUMENT.
Do not infer completion without repository/runtime evidence.
```

Truthful current status:

```text
SOURCE IMPLEMENTATION
✅ COMPLETE

SOURCE RELEASE CANDIDATE
✅ READY

FINAL RUNTIME VALIDATION
⚠ PENDING

FINAL PRODUCTION PASS
❌ NOT YET DECLARED
```

---

## 24. Handoff Instruction

When this file is sent to a new ChatGPT room together with the latest repository ZIP:

1. Read this file first.
2. Inspect the actual ZIP/repository.
3. Treat repository evidence as the source of truth.
4. Preserve completed modules and architecture.
5. Run Final Runtime Gate Closure.
6. Fix actual test/build/MySQL failures.
7. Do not claim PASS without executable evidence.
8. Produce the final release ZIP only after all required runtime gates pass.
9. Update this document with:
   - final version;
   - frontend build result;
   - PHPUnit summary;
   - MySQL migration result;
   - readiness result;
   - final release filename;
   - final release SHA-256.

---

**End of FINAL_RELEASE_NOTES.md**

---

## 25. Roomchat Audit Addendum — 1 October 2026

Audit independen terhadap ZIP aktual pada roomchat baru menghasilkan baseline berikut:

```text
Laravel                  = 13.31.0
PHP requirement          = ^8.4.1
Routes                   = 83 total / 79 API / 4 web
Forced-password routes   = 73
Migration files          = 28
Created tables           = 41
Models                    = 34
Discovered test methods  = 84
PHP lint                  = 148 files / 0 errors
```

Additional verified facts:

- original ZIP integrity (`unzip -t`) = PASS;
- bundled Composer dependency set matches `composer.lock`: 114 locked / 114 installed / 0 version mismatch;
- high-confidence source secret scan = no private key/API-token markers detected outside dependency trees;
- frontend source contains no demo credential literals;
- `/up` local HTTP smoke = 200;
- `/api/health/ready` local HTTP smoke = 503 as expected in the incomplete audit runtime, without exposing database exception details;
- release packager correctly refuses packaging while `public/build/manifest.json` is absent;
- `scripts/verify-release.sh` was corrected to include the required `vendor/bin/pint --test` style gate.
- the uploaded `node_modules` cannot be used as portable build evidence: ZIP extraction did not preserve npm `.bin` symlink semantics for Vite, and the bundled native bindings are macOS arm64-specific;
- `FINALIZE_AND_RUN_MAC.command` was remediated to install Composer/npm dependencies from lockfiles, perform a clean frontend install, require the Vite manifest, run the test suite, and fail closed on readiness errors instead of trusting bundled dependencies.

Runtime blockers remain active in this execution environment:

```text
PHPUnit                 = BLOCKED (dom / mbstring / xmlwriter missing)
Pint                    = BLOCKED (mbstring / xml missing)
MySQL runtime           = BLOCKED (MySQL service + pdo_mysql unavailable)
Clean npm ci            = BLOCKED (registry DNS EAI_AGAIN / incomplete cache)
Vite production build   = NOT EXECUTED
Browser application QA  = BLOCKED by missing production build
FINAL PASS              = NOT DECLARED
```

The package therefore remains a **SOURCE RELEASE CANDIDATE**, not a final production release.
Checkpoint handling:

- no artifact is labeled `FINAL` while mandatory gates are blocked;
- a clean `NADI-LSP-MIGAS-AUDITED-SOURCE-RC-20261001.zip` checkpoint is produced for continuation on a compatible runner;
- the checkpoint excludes `vendor`, `node_modules`, `.env`, runtime database/log/cache data, and stale `public/build`;
- the checkpoint carries `AUDITED_SOURCE_RC_MANIFEST.sha256` for source integrity.



---

## 26. Iteration 14.8 Addendum — Historical Evidence Consistency & Deployment Closure (2 October 2026)

Status tahap ini: **SOURCE HARDENING COMPLETE; FINAL RUNTIME VALIDATION REMAINS PENDING**.

Perubahan yang diverifikasi pada source:

- historical Certification analytics sekarang menghitung passed/failed/pending, lifecycle batch, certificate issuance, dan SLA sesuai `report_as_of`; keputusan/issuance setelah cutoff tidak lagi menulis ulang laporan lama;
- historical Finance invoice rows hanya memuat payment/reversal yang sudah terjadi sampai cutoff;
- historical IT incident rows meredaksi `resolved_at`, resolver, root cause, dan resolution summary yang baru terjadi setelah cutoff;
- historical Governance meredaksi closure/CAPA/appeal evidence masa depan, mengecualikan CAPA yang belum dibuat pada cutoff, dan menerapkan lifecycle forward-only;
- historical Risk/Action meredaksi resolution evidence masa depan;
- historical Management Review pada Risk & Action report sekarang melakukan rollback field review/item dari audit trail sehingga approval/closure/decision/update setelah cutoff tidak bocor ke snapshot lama;
- production deployment melakukan runtime/build/config preflight **sebelum maintenance mode**, lalu migration/cache/full readiness setelah maintenance;
- production readiness menolak placeholder DB credential dan template production URL;
- `composer.json` telah diselaraskan dengan locked dependency set menjadi `PHP ^8.4.1`; `composer.lock` content-hash dihitung ulang menggunakan algoritma Composer dan terverifikasi fresh tanpa mengubah package versions.

Static/source verification setelah hardening:

```text
PHP source lint                 = 150 files / 0 syntax errors
Migration files                 = 29
Created tables                  = 41
Duplicate table creates         = 0
Models                          = 34
Discovered test methods         = 101
Laravel API routes              = 79
Frontend API contracts          = 78
Missing frontend contracts      = 0
Historical fixed demo password  = 0 source hits
```

Controlled deterministic release-engine smoke menghasilkan dua archive byte-identical dari source/epoch yang sama. Digest smoke dicatat pada verification run eksternal dan **bukan** checksum final release.

Smoke tersebut menggunakan controlled Vite-manifest fixture untuk menguji packager dan **bukan** bukti real `npm run build`.

Mandatory gates yang masih belum dapat dinyatakan PASS pada runner audit ini:

```text
Composer clean install                 PENDING / environment blocked
Real MySQL 8 migrate:fresh             PENDING / environment blocked
Full PHPUnit on MySQL                  PENDING / environment blocked
Pint runtime                           PENDING / environment blocked
Clean npm ci + real Vite build         PENDING / package-registry blocked
Production release-check with DB/build PENDING
Full browser E2E                       PENDING
FINAL PRODUCTION PASS                  NOT DECLARED
FINAL ZIP                              NOT CREATED
```

---

## Iteration 14.9 Addendum — Evidence Integrity & Immutable Operational Ledgers (2 October 2026)

Source hardening in this iteration adds deterministic Evidence Pack ZIP generation with per-file SHA-256/byte metadata, model + MySQL database enforcement for immutable financial/certificate/report evidence ledgers, publish-time lifecycle parity for certification and IT integration onboarding, and an exact MySQL 8.x (non-MariaDB) production runtime gate.

Latest source/static evidence after the changes:

```text
PHP source lint                 PASS — 152 files / 0 syntax errors
Migration files                 30
Created tables                  41
Models                          34
Test methods discovered         108 (not runtime execution)
Frontend/backend API contract   PASS — 79 routes / 78 calls / 0 missing
Historical demo password hits   0
Controlled packager engine      PASS — reproducible archive
```

The current execution environment still lacks Composer CLI, PHP `mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`, and a MySQL 8 runtime. A real clean frontend dependency/build gate also remains unavailable. Therefore the project remains a **SOURCE RELEASE CANDIDATE**, not `FINAL PASS`, and no final production ZIP is produced by this iteration.

---

## Iteration 14.10 Addendum — Historical Overview & Fail-Closed Deployment (2 October 2026)

Additional source hardening completed in this iteration:

- dashboard/executive historical overview now reconstructs ActionItem open/overdue counts as-of the requested report cutoff, excludes actions created after that cutoff, and no longer uses the current day for historical overdue calculations;
- overview `last_updated_at` is constrained to operational/configuration events visible at or before the cutoff;
- certificate issuance references are now protected by a database unique invariant when present, with API validation for duplicate issuance references;
- certification batches containing issuance evidence cannot be deleted through Eloquent, and production MySQL receives a parent-delete protection trigger so FK cascade behavior cannot erase the immutable issuance ledger;
- production deployment is now fail-closed after maintenance mode: failures during migration/cache/final readiness leave the application offline for controlled recovery instead of automatically running `artisan up` against a potentially partial release;
- Composer manifest/lock freshness was reverified with the exact Composer-compatible PHP JSON/hash algorithm and remains valid.

Latest static/source evidence:

```text
PHP source files                 156 / 0 syntax errors
Migration files                  31
Created tables                   41 / 0 duplicate creates
Models                           34
Test methods discovered          110 (discovery only)
Laravel route inventory          83 total / 79 API (dependency-cache boot only)
Frontend/backend API contract    PASS — 78 frontend contracts / 0 missing
Historical demo password hits    0
```

A clean `npm ci` was retried but did not complete in the runner network window; its partial dependency tree was removed. The execution environment still lacks Composer CLI, PHP `mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`, and MySQL 8. Therefore no runtime gate is promoted to PASS and no final production ZIP is created by this iteration.

---

## Iteration 14.11 Addendum — Historical Correction Stability & Release Verification Hardening (2 October 2026)

This iteration keeps NADI at **SOURCE RELEASE CANDIDATE** status while closing additional release-critical correctness gaps:

- Decision Center reads are side-effect free; the scheduled risk monitor is the only path that performs automated signal synchronization.
- Management Review creation revalidates current permission and RiskSignal scope on locked rows before commit.
- Legacy/manual KPI CSV import rejects identical file content, keeps KPI configuration snapshots stable under concurrent catalog changes, and audits each measurement correction with before/after values.
- Certification historical analytics now restores post-cutoff outcome/lifecycle corrections from AuditLog history, including cases where a later correction moves `decision_at`/`certificate_due_at` out of the historical reporting period.
- Integration CertificationBatch/IT Incident updates record before/after audit evidence.
- Certification batch creation and integration publishing lock referenced Scheme/TUK/Assessor master rows and re-check availability within the transaction, preventing archive/create races.
- `scripts/verify-release.sh` now requires runtime preflight, API-contract validation, actual MySQL runtime assertion, PHPUnit forced to MySQL, Pint, production release-check, and release hygiene.
- CI deterministic package verification now builds and compares two archives byte-for-byte.

Latest clean-source evidence:

```text
PHP source lint                 PASS — 156 files / 0 syntax errors
Migration files                 31
Created tables                  41 / 0 duplicate creates
Models                          34
Test methods discovered         114 (not runtime execution)
Frontend/backend API contract   PASS — 79 routes / 78 frontend contracts / 0 missing
Historical demo password hits   0
Composer manifest/lock          FRESH
```

The execution environment still lacks Composer CLI, PHP `mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`, and MySQL 8. A real clean frontend dependency/build gate also remains unavailable. Therefore clean Composer install, MySQL `migrate:fresh`, full PHPUnit-on-MySQL, Pint, real Vite build, production release-check with live DB/build, and browser E2E remain pending. No FINAL production ZIP is produced by this iteration.

Controlled release-packager smoke for Iteration 14.11 produced byte-identical archives (`d8bca871ad137b2c626016dce8d566f2498c60bc019b2676a53d706bf94261b3`) with valid ZIP integrity, a generated `RELEASE_MANIFEST.json`, and zero runtime `.env`, dependency directories, Python cache, demo database, or runtime-log entries. This digest belongs only to the synthetic-build packager verification and is **not** a final release checksum.

## Iteration 14.12 Addendum — Evidence Boundary & Source Closure (2 October 2026)

Iteration 14.12 closes the remaining source-level evidence and authorization gaps identified after Iteration 14.11:

- security audit/authentication events are append-only;
- import-batch provenance identity is immutable and import evidence cannot be hard-deleted;
- governance/provenance parent deletion is protected against evidence loss;
- user identity and Decision/Management Review records use lifecycle transitions rather than hard delete;
- report/master-data/KPI/import actor authorization is revalidated under row locks at commit time;
- report source manifests are domain-scoped, preventing cross-department provenance metadata leakage;
- legacy KPI CSV provenance creation revalidates actor/source state before the batch exists and preserves the immutable creator as its audit actor.

Clean static evidence after these changes:

```text
PHP source files                 155 / 0 syntax errors
Migrations                       33
Created tables                   41 / 0 duplicate creates
Models                           34
Test methods discovered          119 (not runtime execution)
Laravel API routes (static)      79
Frontend API contracts           78 / 0 missing
Historical fixed demo password   0 hits
Composer manifest/lock           FRESH
```

The environment still lacks Composer CLI, PHP `mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`, and MySQL 8. A local extension-build fallback is not viable because PHP development headers/`phpize`/`php-config` are absent. Clean `npm ci` also remains unable to complete in the available runner/network window; partial dependencies are removed after each attempt. Therefore the remaining work after Iteration 14.12 is **runtime-only release closure**, not further feature expansion: clean installs, real Vite build, MySQL 8 fresh migration, full PHPUnit-on-MySQL, Pint, production readiness, HTTP/browser E2E, then deterministic FINAL packaging.

Controlled release-engine verification for the final Iteration 14.12 source state produced two byte-identical archives with SHA-256 `8e5d84ab8c58c1a353be3a19988daa8af70a234979c6ae48416019a08e11b2a0`; ZIP integrity passed, `RELEASE_MANIFEST.json` was present, and forbidden runtime/dependency/cache entries were zero. This digest belongs only to the synthetic-build packager verification and is **not** a final release checksum. The synthetic `public/build` fixture and smoke archives were removed after verification.

## Iteration 15.1 — Runtime validation entry and release-gate safety

Iteration 15 has started. The source remains closed for feature expansion; work is now focused on executable production validation and remediation.

Release engineering changes in 15.1:

- clean MySQL `migrate:fresh` is now explicitly part of `scripts/verify-release.sh` before PHPUnit;
- destructive verification is guarded by `NADI_VERIFY_DESTRUCTIVE_DATABASE` and a test/CI/verify-scoped database-name check, preventing accidental `migrate:fresh` against production;
- production `APP_KEY` readiness verifies a non-placeholder 32-byte AES-256-CBC key, and CI generates an ephemeral random key;
- production readiness requires database-backed session/cache/queue;
- runtime-only deployment preflight no longer requires Node/npm on an application server that receives compiled frontend assets;
- deployment verifies `RELEASE_MANIFEST.json` file size/SHA-256 before Composer or database operations;
- Vite manifest packaging rejects unresolved imported/dynamic manifest entries;
- GitHub Actions checkout was moved to the current major and production-like CI checks use durable cache/queue settings.

Executable integrity tests in this iteration confirmed:

```text
intact extracted release manifest verification = PASS
single-file tamper detection                   = FAIL as required
missing Vite import manifest entry             = FAIL as required
frontend/backend API contract                  = PASS (79 / 78 / 0 missing)
```

Local mandatory runtime gates are still blocked by the execution environment: no Composer CLI, PHP `mbstring/dom/xml/xmlwriter/pdo_mysql`, or MySQL 8 server; registry DNS also returns `EAI_AGAIN` during clean `npm ci`. A lock-matched historical vendor cache was used only for Laravel boot diagnostics and was removed; it is not clean-install/test evidence. `FINAL PASS` remains undeclared.

Final controlled Iteration 15.1 packager smoke after all source/document updates produced two byte-identical archives with SHA-256 `a33e2397584fd8165063c179dfe6412c2f92407c1c6eae16b9cca0f6fd5c7d1c`; ZIP integrity passed. This is synthetic-build release-engine evidence only, not the final release checksum.

## Iteration 15.2 — Production Acceptance Automation & Closed-World Artifact Verification (2 October 2026)

Iteration 15.2 continues runtime-closure work without changing the product feature scope.

Release/acceptance hardening completed:

- Added `scripts/http_acceptance.sh` for production-like HTTP acceptance. It verifies the SPA shell and compiled Vite references, liveness/readiness, unauthenticated access boundaries, demo endpoint shutdown, required security headers, CSRF-aware session login, authenticated read access across all principal NADI modules as an ephemeral director, logout, and post-logout session invalidation.
- GitHub Actions now creates a random ephemeral acceptance administrator and test/demo passwords per run rather than storing fixed CI passwords in source.
- CI `migrate:fresh` now uses the same destructive verification-database guard as the local release verification script.
- HTTP acceptance supports an explicit Host header so loopback CI can exercise production-style trusted-host behavior without changing DNS.
- Added regression coverage for CSP/security headers and production HTTPS HSTS behavior.
- Release-manifest verification is now closed-world: every source/application file in an extracted release must be listed in `RELEASE_MANIFEST.json`; unexpected files and all symlinks are rejected. Only explicit mutable runtime locations (`.env`/`.env.production` and storage runtime paths) are allowed outside the manifest.
- CI deterministic-package smoke now extracts the package, verifies its release manifest, then intentionally adds an unexpected PHP file and requires the verifier to reject it.
- `bootstrap/cache` is deliberately not an allowed manifest exception; executable Laravel cache files must be generated only after artifact verification.

Executable artifact-integrity evidence in this iteration:

```text
intact extracted artifact       PASS
modified manifest-covered file  REJECTED
extra public PHP file           REJECTED
unexpected symlink              REJECTED
runtime .env exception          PASS
```

Runtime-environment status is unchanged: the local runner still lacks Composer CLI, PHP `mbstring/dom/xml/xmlwriter/pdo_mysql`, and a MySQL 8 server. The local npm cache is empty and `npm ci --offline` fails with `ENOTCACHED`; registry access remains unavailable for a real clean Vite build. Therefore Composer install, real Vite build, MySQL fresh migration, PHPUnit-on-MySQL, Pint, live production release-check, and browser-render E2E remain pending. No `FINAL PASS` or final production ZIP is declared by Iteration 15.2.

Iteration 15.2 clean-source inventory after acceptance/integrity hardening: 156 PHP source files with zero syntax errors, 33 migrations, 41 table-creation declarations, 34 models, 122 discovered test methods (inventory only), 79 Laravel API routes, 78 frontend API contracts with zero missing backend contracts, and a Composer manifest/lock content hash match. Real clean `npm ci` still does not complete in the available runner; no frontend-build PASS is claimed.


---

## Iteration 15.3 Addendum — Production Acceptance / Runtime Gate Closure (5 October 2026)

Status: **RELEASE CANDIDATE — NOT FINAL PASS**.

This checkpoint revalidated the exact Iteration 15.2 handoff ZIP, re-ran source inventory/lint/API-contract checks, and retried runtime prerequisites. The current runner still lacks Composer, required PHP extensions (`mbstring`, `dom`, `xml`, `xmlwriter`, `pdo_mysql`), and MySQL 8. Clean `npm ci` again failed because registry DNS returned `EAI_AGAIN`; therefore no real Vite manifest was produced and downstream runtime/browser/final-package gates remain open.

A release-engineering discrepancy was corrected: Laravel runtime placeholder `.gitignore` files omitted from the 15.2 checkpoint were restored, and the stale source checkpoint manifest was refreshed for the current source. Business modules and completed hardening invariants were not reopened.

## Iteration 15.4A — Acceptance Gate Completeness Hardening (5 October 2026)

Release acceptance was hardened without reopening business scope. HTTP acceptance now fails closed when authenticated credentials are absent, supports explicit forwarded-HTTPS/HSTS verification, and only permits public-only checks through an explicit non-final override. A pinned Playwright browser-render acceptance gate was added and wired into the release workflow, together with deterministic forced-password acceptance identity creation via `nadi:create-admin --force-password-change`. Static/tooling regressions pass in this runner; real runtime/browser PASS remains pending because the environment still lacks the required Composer/PHP/MySQL/npm/browser execution path. No FINAL production ZIP is created.

## Iteration 15.4B — Browser Functional Scenario Coverage & Release Evidence Consolidation (5 October 2026)

The browser release gate now validates functional acceptance rather than only route rendering. It exercises immutable report creation and hash-bound CSV/JSON/Evidence Pack exports, Notification Center transport state, user creation through the real administration UI, forced-password onboarding, restricted navigation, route redirection, API/object-level RBAC denial, responsive rendering, keyboard focus, and logout. Browser evidence is retained as a GitHub Actions artifact on both success and failure paths. This is acceptance-tooling closure only; live browser/runtime PASS still requires the real Composer/npm/MySQL 8/Vite/PHPUnit/Pint production-like execution environment.

---

## Iteration 15.4C release-gate hardening

Iteration 15.4C does not add business scope. It closes release-acceptance control gaps by introducing one authoritative fail-closed final-gate orchestrator, isolated acceptance database resets, source-bound tamper-evident browser evidence, CI/local orchestration convergence, and explicit exclusion of `artifacts/` from release packages.

`FINAL PASS` remains withheld until the authoritative gate executes successfully against the required real runtime stack.


## Iteration 15.4D — Local/CI Runtime Runner Convergence & Execution Readiness (5 October 2026)

Release verification now has one implementation path: `scripts/final-gate.sh`. GitHub Actions invokes it directly; `scripts/verify-release.sh` is a compatibility wrapper; and `FINALIZE_AND_RUN_MAC.command --release-gate` delegates to the same script. The macOS default `--demo` path remains deliberately SQLite/synthetic and is explicitly non-production.

A new `scripts/release-gate-doctor.sh` performs early orchestration readiness checks for required commands, environment inputs, destructive-database acknowledgement, cross-platform SHA-256 tooling, and Playwright Chromium. Final-gate SHA generation is portable across GNU/Linux and macOS, and failure evidence has a shell fallback if Python itself is missing. `docs/RELEASE_GATE_EXECUTION.md` documents the single gate contract and retry semantics.

Controlled tests confirmed that the direct gate, compatibility wrapper, and macOS release-gate entrypoint all fail through the same authoritative phase in the current blocked runner. No runtime gate was promoted to PASS; FINAL PASS remains withheld.

## Iteration 15.5 — Runtime Gate Execution & Failure Triage (5 October 2026)

The authoritative final gate was executed in the available runner and truthfully failed at the execution-readiness doctor. After supplying complete verification-only gate variables, the remaining blockers were isolated to missing Composer, PHP `mbstring/dom/xml/xmlwriter/pdo_mysql`, unavailable MySQL 8, and unavailable package-registry connectivity. npm cache coverage was measured against `package-lock.json`: only 18/178 resolved tarball URLs are locally cache-matched, so clean offline installation is not viable.

A release-tooling portability issue was fixed: browser acceptance and the doctor now support an explicit environment-managed Chromium executable through `NADI_BROWSER_EXECUTABLE_PATH`, while preserving mandatory Playwright execution. System Chromium launches in this runner, but loopback navigation is blocked by runner policy (`ERR_BLOCKED_BY_ADMINISTRATOR`). No business scope was reopened and no blocked runtime gate is claimed as PASS.

## Iteration 15.6 — Runtime Environment Bootstrap Contract & CI-Executable Closure (5 October 2026)

Iteration 15.6 keeps product/business scope frozen and closes release-host bootstrap drift.

Release engineering changes:

- added `config/release-environment.json` as the explicit release-host contract for Composer major, PHP extensions, Python minimum, MySQL major, required commands, and required gate environment presence;
- added standard-library-only `scripts/release_environment.py`, which cross-checks PHP/Node/npm/Playwright requirements against canonical source manifests and produces machine-readable, secret-redacted runtime evidence before clean dependency installation;
- readiness now verifies actual raw-PDO connectivity to MySQL major version 8, verification-database scope/acknowledgement, APP key shape, PHP extensions, Playwright package version, and Chromium availability;
- added `scripts/generate-release-gate-env.sh` so local/manual release execution and GitHub Actions generate ephemeral APP keys and acceptance identities through the same implementation;
- the credential helper refuses non-test/CI/verify database names and never creates or prints database credentials;
- `scripts/release-gate-doctor.sh` now delegates to the executable environment contract instead of maintaining a parallel prerequisite implementation;
- `scripts/final-gate.sh` records `runtime_contract.json` and `runtime_environment.json` and cryptographically binds them into both success/failure evidence;
- `/artifacts` is explicitly source-control ignored and remains excluded from production packaging;
- added `docs/RUNTIME_BOOTSTRAP.md` and aligned release execution documentation/README.

Controlled regression evidence:

```text
release contract alignment             PASS
intentional npm-engine contract drift  REJECTED
production DB acknowledgement helper   REJECTED
verification DB helper                 PASS
secret serialization in runtime JSON   0 detected
final gate related-evidence linkage     PASS
PHP source lint                         158 / 0 errors
frontend/backend API contract           79 / 78 / 0 missing
workflow/shell/Python parsing           PASS
```

The authoritative gate still fails closed in the current runner because Composer, required PHP extensions, and actual MySQL 8 runtime are unavailable. This checkpoint makes those blockers reproducible and machine-readable; it does not convert them into PASS. Clean Composer/npm, real Vite build, MySQL migration, PHPUnit-on-MySQL, Pint, production readiness, HTTP/browser acceptance, and real-build final packaging remain mandatory before `FINAL PASS`.

## Iteration 15.7 — CI Gate Execution & Runtime Evidence Ingestion

Release evidence transport is now source-bound and closed-world. `scripts/ci_evidence.py` bundles the authoritative release-gate evidence, browser acceptance evidence, and—only after PASS—the exact gate-produced release ZIP into one SHA-256-manifested CI artifact. The verifier rejects source mismatch, tampering, unexpected files, incomplete PASS gate matrices, invalid acceptance evidence, and package hash mismatch. Failed CI runs remain ingestible for diagnosis but are never release-authorizable.

GitHub Actions now uploads a unified run/attempt-specific CI evidence bundle after the authoritative gate and includes repository/commit/run/runner provenance. Raw evidence remains available as failure diagnostics. This checkpoint improves evidence custody only; it does not claim a real end-to-end CI/runtime PASS in the current runner.

## Iteration 15.8 — External CI Runtime Execution, Evidence Import & Release Decision (5 October 2026)

Iteration 15.8 closes the production release-decision boundary without claiming a real external runtime PASS. CI PASS evidence is now distinct from production authorization: only a GitHub Actions `workflow_dispatch` on `main`, using the expected `NADI Release Gates` workflow and an explicit semantic version, may become release-authorizable. Ordinary push/PR PASS bundles and `ci-gate` packages remain diagnostic/release-candidate evidence only.

The workflow now accepts and validates a production `release_version`. `scripts/ci_evidence.py` records stronger GitHub provenance and applies production authorization policy during ingestion. New `scripts/release_decision.py` re-verifies the ingested PASS bundle, requires explicit repository identity, executes the extracted package's closed-world release-manifest verifier, and only then creates a byte-identical `NADI-LSP-MIGAS-FINAL-<version>.zip` copy plus SHA-256 and `FINAL_RELEASE_DECISION.json`. No post-CI repackaging is permitted.

Controlled synthetic policy regression passed, including rejection of repository mismatch, ordinary push authorization, and placeholder `ci-gate` version. No accessible NADI GitHub repository or real external PASS artifact is available in this room, so FINAL PASS remains withheld.

## Iteration 15.9 — External CI Artifact Consumption (5 October 2026)

Iteration 15.9 adds a fail-closed operational consumer for a real downloaded GitHub Actions release-evidence artifact. `scripts/consume_external_ci.py` accepts the artifact ZIP or extracted bundle, safely discovers the closed-world evidence root, requires source-bound PASS evidence and production-authorizable GitHub provenance, matches the expected repository, ingests evidence append-only, runs the final release decision in staging, verifies the final package SHA-256, and only then atomically publishes the final output directory. Successful consumption writes `EXTERNAL_CI_CONSUMPTION.json` to bind the external artifact, CI bundle, ingestion receipt, release decision, source checkpoint, and final ZIP.

No real NADI GitHub repository or external PASS artifact is accessible from the current room, so this iteration does not claim external runtime PASS and does not create a production FINAL package.

## Iteration 15.10 — Production Deployment Artifact Intake & Post-Deploy Verification Contract

Iteration 15.10 hardens the boundary after external-CI release authorization. A FINAL ZIP can no longer be treated as sufficient deployment input by itself. `scripts/production_deployment_intake.py` creates a closed-world deployment envelope only from the exact FINAL output produced by the source-bound external-CI consumer, preserving the FINAL decision, external-CI consumption receipt, release ZIP/checksum, and safely extracted release tree.

`scripts/verify_deployment_intake.php` provides a PHP-only production-host provenance gate before Composer/database work. `scripts/deploy-production.sh` now requires deployment-intake/repository/post-deploy inputs and runs an 11-phase fail-closed deployment sequence. `scripts/post_deploy_verify.sh` produces target-environment acceptance evidence only after production release-check, scheduler presence, liveness/readiness/SPA probes, and required HTTPS security headers pass.

A post-deploy verification failure returns the application to maintenance mode and does not perform automatic schema rollback. Controlled regression validates the mechanism only; no real external CI PASS, production deployment, or production post-deploy PASS exists in this room, so FINAL PASS remains withheld.

## Iteration 15.11 — Production evidence custody and operational acceptance

- Preserved raw post-deploy verification evidence in a closed-world SHA-256-manifested bundle instead of deleting it with the temporary work directory.
- Extended post-deploy evidence to bind intake verification, production release-check, scheduler output, liveness/readiness/SPA status codes, and security headers.
- Added `scripts/production_operational_acceptance.py` for safe ZIP/directory intake, exact source/deployment-envelope provenance verification, independent evidence revalidation, append-only ingestion, and atomic operational acceptance decision creation.
- Production operational acceptance now requires the same expected GitHub repository, `workflow_dispatch`/`main` provenance, source checkpoint hash, release version, release manifest hash, deployment intake hash, and complete PASS post-deploy check matrix.
- Controlled positive/negative regression passed for generated bundle consumption, ZIP consumption, evidence tamper, unexpected file, repository mismatch, and archive path traversal rejection.
- No real target-environment deployment evidence was promoted. `FINAL PASS` remains false.

## Iteration 15.12 — Production Evidence Export / Release Closure Handoff — 5 October 2026

Added the final source/tooling custody layer for production release closure. `scripts/release_closure_handoff.py` can export a self-contained closure package only after the same source checkpoint has both an authorized deployment envelope and an independently accepted production post-deploy evidence set. The closure carries the complete deployment envelope and accepted evidence, plus a closed-world SHA-256 manifest/digest and `RELEASE_CLOSURE.json`.

The verifier re-runs deployment/post-deploy provenance checks, validates the production operational-acceptance decision, re-extracts the custodied FINAL ZIP and proves it matches the deployment release tree, and rejects source/repository mismatches, tampering, extra files, symlinks/path traversal, and recognized secret material. `docs/RELEASE_CLOSURE_RUNBOOK.md` documents the complete external-CI-to-closure sequence.

This is source/tooling closure only. No real external CI PASS, production deployment, production operational acceptance, or real release-closure bundle is claimed by this checkpoint.


## Iteration 15.13 — Release Closure Verification Matrix / Operator Handoff Hardening (5 October 2026)

Iteration 15.13 keeps business scope frozen and removes operator ambiguity from release closure. A canonical eight-gate matrix now maps source integrity, authoritative external CI, artifact consumption, deployment intake, post-deploy verification, operational acceptance, closure export and independent closure verification to explicit evidence authorities and required states.

`scripts/release_closure_matrix.py` validates the matrix/source checkpoint, reports truthful waiting state when external evidence is absent, independently delegates real closure verification to the existing closure verifier, and exports a non-overwriting closed-world operator handoff packet with SHA-256 manifest/digest. Controlled regression confirms all eight gates PASS only for a fully verified controlled closure fixture; wrong repository, packet tampering, extra files, overwrite attempts and synthetic-authority policy mutations are rejected. No controlled fixture is promoted as real production evidence.

The authoritative `scripts/final-gate.sh` now validates the source/matrix at its initial readiness phase. `FINAL PASS` remains withheld pending real external CI and real production evidence.

## Iteration 15.14 — Release Archive Retention / Recovery Verification & Operational Closeout (5 October 2026)

Iteration 15.14 adds post-G08 operational custody tooling without changing the frozen G01–G08 release authorization matrix.

- added `config/release-archive-policy.json`; the repository intentionally does not assert an official LSP Migas retention duration and requires an operator-supplied approved `retention_until_utc`;
- added `scripts/release_archive.py` with non-overwriting `create`, `verify`, `rehearse`, and `closeout` flows;
- retained archives are closed-world, SHA-256 manifested, source/matrix/policy bound, repository/version bound, secret-leak guarded, and require an independently verified closure ZIP plus verified operator packet;
- recovery rehearsal re-runs deep release-closure verification, including recovery of the custodied FINAL ZIP and comparison to the deployment release tree;
- operational closeout is bound to the exact archive SHA-256 and matching recovery receipt, and is refused when the retention window is expired;
- added `docs/RELEASE_ARCHIVE_AND_RECOVERY.md` and cross-linked release-closure/database-recovery boundaries.

Controlled regression passed the complete synthetic mechanism path through archive creation, verification, recovery rehearsal, and operational closeout. Tampering, extra files, wrong repository, path traversal, credential leakage, invalid/past retention, substituted recovery receipt, and expired-retention closeout were rejected. Two archive ZIP builds with identical explicit source epoch/inputs were byte-identical. Controlled fixtures remain non-authoritative and no real external CI/deployment/production closure is claimed.

## Iteration 15.16 — External Runtime Target Binding & Real CI Evidence Intake (5 October 2026)

Iteration 15.16 hardens the external execution identity boundary. Production-authoritative evidence can no longer be accepted by matching only a GitHub `owner/repository` string. The release-control path now supports an explicit target binding containing the numeric GitHub repository ID, GitHub server URL, default branch/ref, workflow identity, frozen workflow hash, exact checkpoint SHA-256 and source-manifest fingerprint.

`ci_evidence.py` records the corresponding GitHub Actions provenance (`GITHUB_REPOSITORY_ID`, `GITHUB_SERVER_URL`, `GITHUB_WORKFLOW_REF`, and `GITHUB_WORKFLOW_SHA`), while `consume_external_ci.py` requires a verified target binding before PASS evidence can be ingested or promoted to a release decision. A target request without a real repository identity remains non-authorizing.

No real NADI GitHub repository is accessible from the current connected GitHub context, so no repository ID, workflow dispatch, or real external CI PASS is fabricated. FINAL PASS remains withheld.

## Iteration 15.17 — GitHub Repository Onboarding Package / Target Binding Execution Readiness (6 October 2026)

Iteration 15.17 closes the repository-onboarding ambiguity without reopening the NADI business/application scope. A new closed-world onboarding package preserves the exact frozen checkpoint and target request while remaining explicitly non-authorizing until a real GitHub repository exists.

Production-authoritative target binding is strengthened: `scripts/external_runtime_target.py bind-metadata` accepts metadata exported from the real GitHub REST repository object and records its SHA-256 plus normalized repository/API identity. The manual `bind` path is retained only for diagnostic compatibility and now emits a non-authorizing binding. Archived/disabled repositories, mismatched owner/full-name/server/default-branch identity, source/checkpoint drift, or later CI provenance mismatch are fail-closed.

The connected GitHub context still exposes no NADI repository, so no real repository ID, workflow dispatch, or external CI PASS is fabricated. FINAL PASS remains withheld.

## Iteration 15.18 — Repository Materialization & Commit-Bound CI Authority

Iteration 15.18 closes the gap between the frozen source checkpoint and GitHub Actions execution by introducing deterministic Git materialization. The exact checkpoint is converted into one root commit on `main`, exported as a deterministic Git bundle, cloned and source-freeze verified again. Production-authoritative target binding now requires a GitHub REST remote-HEAD confirmation showing `refs/heads/main` equals that materialized commit. External CI provenance must carry the same `GITHUB_SHA`; a green run from another commit is rejected. Repository provisioning, real remote confirmation, and real workflow dispatch remain external/pending because no NADI GitHub repository is accessible in the current connected GitHub context.
