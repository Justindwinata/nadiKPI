# NADI Real Data Onboarding & Integration

## Tujuan

Layer ini digunakan untuk memindahkan data perusahaan ke NADI tanpa melakukan `INSERT` langsung ke tabel operasional. Semua file masuk melalui urutan terkontrol:

```text
Source System / CSV
        ↓
Data Source Registry
        ↓
SHA-256 + Import Batch
        ↓
Staging Rows
        ↓
Column Mapping / Profile
        ↓
Validation + Dependency Check
        ↓
Idempotency Check
        ↓
Publish Transaction
        ↓
Operational Ledger
        ↓
Integration Record Link
        ↓
KPI / Dashboard / Reconciliation
```

## Prinsip keselamatan data

1. **Tidak ada direct-import ke ledger.** Upload hanya membuat staging batch.
2. **File fingerprint.** File yang identik dari source dan dataset yang sama ditolak agar operator tidak memproses batch yang sama dua kali.
3. **External key per row.** Setiap record memiliki business/external key yang stabil.
4. **Row hash.** Payload yang sama menghasilkan `skip`; payload berubah menghasilkan `update` hanya pada dataset mutable.
5. **Immutable ledger.** Invoice, pembayaran, ledger finance, penerbitan sertifikat, dan audit kualitas data tidak dioverwrite. Perubahan existing harus mengikuti reversal/void/workflow domain.
6. **All-or-nothing default.** Publish gagal bila masih ada row invalid. Partial publish hanya tersedia lewat API dengan flag eksplisit dan meninggalkan reconciliation exception.
7. **Provenance.** `integration_record_links` menghubungkan source + dataset + external key ke entity NADI dan batch terakhir.
8. **Authority precedence.** Source ber-authority lebih rendah tidak dapat mengubah entity yang sudah dimiliki source ber-rank lebih tinggi.
9. **Audit trail.** Stage, mapping, profile, dan publish dicatat di `audit_logs`.

## Dataset yang didukung

### Sertifikasi

- `certification_schemes`
- `tuks`
- `assessors`
- `certification_batches`
- `certificate_issuances`

Urutan onboarding yang disarankan:

```text
Skema → TUK → Asesor → Batch → Penerbitan Sertifikat
```

Batch dengan status `decision/completed` harus memiliki hasil final dan timestamp keputusan. Batch hanya boleh masuk sebagai `completed` setelah seluruh issuance peserta kompeten sudah tercatat.

### Keuangan

- `finance_invoices`
- `finance_payments`
- `financial_records`

Urutan yang disarankan:

```text
Invoice → Payment

Budget / Expense / non-invoice revenue → Financial Record
```

Invoice otomatis membentuk revenue posting. Record Finance existing tidak diubah dari import; gunakan reversal/void pada workflow Finance.

### Teknologi Informasi

- `it_services`
- `it_incidents`
- `data_quality_runs`

Urutan yang disarankan:

```text
IT Service → Incident
Data Quality Run dapat diimpor independen
```

Untuk `it_services`, field canonical `monitoring_started_at` dianjurkan saat onboarding histori. Nilai ini menyatakan kapan layanan mulai masuk cakupan SLA/monitoring NADI; uptime sebelum timestamp tersebut tidak dimasukkan ke denominator. Jika record baru tidak membawa nilai ini, publisher menggunakan waktu onboarding sebagai default aman.

## Mapping kolom

Template resmi menggunakan nama canonical, sehingga auto-map berjalan tanpa konfigurasi. Untuk source lama dengan header berbeda:

1. Upload file dan pilih source + dataset.
2. Batch tetap dibuat meskipun required field belum terpetakan.
3. Buka **Review**.
4. Pilih source column untuk setiap canonical field.
5. Jalankan **Terapkan mapping & validasi ulang**.
6. Bila mapping akan dipakai berulang, simpan sebagai Integration Profile.

## Idempotency

Identitas row ditentukan oleh `data_source_id + dataset_type + external_key`.

- Belum pernah ada → `insert`
- Sudah ada dengan row hash sama → `skip`
- Sudah ada dengan isi berbeda dan dataset mutable → `update`
- Sudah ada dengan isi berbeda dan dataset immutable → `invalid/exception`

Dengan pola ini, export mingguan yang membawa record lama dan baru hanya menerapkan delta.

## Production onboarding checklist

Sebelum source nyata dipakai:

- daftarkan source pada Governance dan tentukan owner + authority rank;
- sepakati external key yang stabil;
- ambil sample export dan buat mapping profile;
- jalankan batch kecil terlebih dahulu;
- review `invalid_rows`, dependency error, dan duplicate count;
- backup database sebelum publish awal skala besar;
- publish master/reference sebelum transaksi yang bergantung padanya;
- cek reconciliation status = `reconciled`;
- cocokkan total source vs NADI dengan pemilik proses;
- simpan evidence export/source control sesuai kebijakan perusahaan.

## Batas batch

Satu upload dibatasi **5.000 row** agar review, transaction, dan audit tetap terkendali. Volume lebih besar harus dipecah berdasarkan periode/domain atau dikembangkan menjadi connector/queue ingestion pada deployment enterprise.

## Operational invariant parity at publish time (Iteration 14.9)

Integration is not a bypass around operational lifecycle rules. Publish-time mutation re-validates invariants after the target entity is locked, even when the staging row was previously valid.

For certification batches this includes forward-only lifecycle, result completeness, assessment/decision chronology, existing certificate issuance constraints, completion requirements, and system-derived certificate due date. For IT incidents this includes the forward-only `open -> investigating -> resolved` lifecycle, acknowledgement/resolution chronology, and mandatory resolution evidence.

These publish-time checks are intentionally repeated after staging validation to close staging-to-publish TOCTOU windows.
