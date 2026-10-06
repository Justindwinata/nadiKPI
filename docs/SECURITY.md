# NADI Production Security & Deployment Checklist

Dokumen ini adalah baseline deployment. Sesuaikan dengan kebijakan keamanan, IAM/SSO, jaringan, backup, dan change-management LSP Migas sebelum go-live.

## 1. Environment

- Gunakan `.env.production.example` sebagai titik awal; jangan deploy `.env` lokal atau credential demo.
- `APP_ENV=production`, `APP_DEBUG=false`, `VITE_DEMO_MODE=false`.
- Gunakan HTTPS end-to-end atau TLS termination yang terpercaya.
- Isi `APP_KEY` dengan `php artisan key:generate`; jangan mengganti APP_KEY pada sistem aktif tanpa rencana migrasi karena dapat memutus session/encrypted payload.
- Gunakan secret manager atau mekanisme rahasia server untuk DB/SMTP credentials; jangan commit ke repository.

## 2. Database dan session

- Gunakan MySQL 8.x dengan user aplikasi least-privilege; jangan gunakan akun root.
- Backup dan uji restore sebelum migrasi production.
- Jalankan `php artisan migrate --force`, bukan `migrate:fresh`.
- Gunakan `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`.
- Batasi koneksi database ke host aplikasi yang diperlukan.

## 3. Bootstrap identity

Jangan menjalankan `PrototypeSeeder` di production. Buat administrator pertama:

```bash
php artisan nadi:create-admin admin@perusahaan.co.id --name="Administrator NADI"
```

Gunakan password unik yang kuat. Buat akun operasional melalui halaman Pengguna & Akses dan gunakan role/permission paling minimum yang diperlukan.

## 4. Authorization dan segregation of duties

- Tinjau default permission untuk setiap role/divisi sebelum onboarding user nyata.
- Gunakan override hanya untuk pengecualian; setiap override harus memiliki alasan.
- Pisahkan kemampuan pencatatan transaksi dari reversal/approval bila proses internal membutuhkan maker-checker.
- `users.manage` dipertahankan untuk director; hindari pemberian privilege administratif melalui akun operasional harian.

## 5. Authentication monitoring

- Tinjau `authentication_events` dan `audit_logs` secara rutin.
- Login memakai limiter per akun+IP dan per IP; sesuaikan `NADI_LOGIN_*` terhadap kebijakan perusahaan.
- Akun baru/reset diwajibkan mengganti password sebelum menggunakan route operasional.
- Nonaktifkan akun segera ketika akses tidak lagi diperlukan; deactivation memutus session aktif.

## 6. Web/application hardening

- Pastikan reverse proxy meneruskan HTTPS dan header host secara benar.
- Security headers sudah ditambahkan oleh middleware; validasi kembali setelah konfigurasi proxy/CDN.
- Batasi ukuran upload pada web server/PHP sesuai kebutuhan import.
- Nonaktifkan directory listing dan jangan expose `.env`, `storage`, source map sensitif, atau file backup.
- Jalankan service Laravel menggunakan user OS non-root.

## 7. Build dan release

Pada release host/CI yang memiliki dependency lengkap:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Sebelum release, jalankan:

```bash
php artisan test
./vendor/bin/pint --test
```

Jangan menyertakan `node_modules`, `.env`, database demo, log lokal, atau credential pada source ZIP/release artifact.

## 8. Operational controls

- Jadwalkan backup DB dan verifikasi restore berkala.
- Simpan log aplikasi di storage terproteksi dan atur retention.
- Rekonsiliasi import batch sebelum data dipakai untuk keputusan manajemen.
- Gunakan provenance dan audit trail untuk investigasi angka KPI, koreksi transaksi, serta perubahan akses.
- Lakukan review permission berkala dan setelah perpindahan jabatan/divisi.


## Integration onboarding security

- Permission `integrations.manage` diperlukan untuk stage, mapping, profile, dan publish.
- Controller tetap melakukan domain scoping; memiliki permission integration tidak otomatis memberi hak menulis dataset divisi lain.
- Upload dibatasi CSV/text maksimal 10 MB dan 5.000 row per batch.
- File mendapat SHA-256 fingerprint dan tidak dieksekusi sebagai konten aktif.
- Publish default all-or-nothing dan seluruh stage/publish event masuk audit trail.
- Finance/certificate/data-quality ledger yang immutable tidak dapat dioverwrite melalui integration layer.
- Data source production harus didaftarkan dengan owner serta authority rank sebelum onboarding.

## Configuration governance

- `kpi.catalog.view` memberikan akses baca ke katalog KPI sesuai scope divisi.
- `kpi.catalog.manage` diperlukan untuk membuat KPI custom, mengubah metadata, dan membuat versi target/threshold/bobot baru. Department head dibatasi ke KPI divisinya; director dapat mengelola lintas divisi.
- Versi konfigurasi KPI bersifat append-only dan wajib memiliki alasan perubahan. Histori versi tidak diedit atau dihapus dari UI.
- Master data tidak dihapus untuk koreksi operasional. Gunakan edit, archive, atau restore; setiap aksi menyimpan before/after state dan alasan pada audit log.
- Archive master ditolak jika masih ada dependency operasional aktif. Record terarsip juga tidak valid untuk transaksi atau onboarding baru.

## Release hardening (Iteration 12)

- Production frontend **tidak** mengaktifkan demo mode secara default. `VITE_DEMO_MODE` harus diaktifkan eksplisit hanya untuk prototype lokal.
- `PrototypeSeeder` menolak berjalan di environment `production` kecuali `NADI_ALLOW_DEMO_SEED=true` diberikan secara eksplisit. Deployment normal menggunakan `php artisan nadi:create-admin` dan onboarding data nyata.
- Runtime UI tidak lagi memuat font/CDN eksternal; production asset dapat dilayani self-hosted dari `public/build`.
- Host validation memakai Laravel trusted-host middleware. Untuk reverse proxy yang dipercaya, `NADI_TRUSTED_PROXIES` dapat dikonfigurasi eksplisit dan harus dibatasi ke proxy perusahaan.
- Production response menerapkan CSP, `X-Frame-Options: DENY`, `nosniff`, restrictive permissions policy, COOP/CORP, dan HSTS ketika request HTTPS.
- `/api/health/ready` sengaja berada di luar session middleware agar kegagalan database/session menghasilkan status `503` yang aman, bukan exception `500`. Response tidak memuat credential, SQL, stack trace, atau detail exception.
- Generated Laravel cache (`bootstrap/cache/packages.php`, `services.php`, route/config/event cache) bukan source artifact dan harus dibuat ulang setelah dependency production terpasang.
- Release packager menolak membuat ZIP tanpa Vite manifest dan mengecualikan `.env`, database SQLite demo, log/cache runtime, `vendor`, `node_modules`, dan tooling AI/development.
- Gunakan `scripts/final-gate.sh` sebagai **satu-satunya authoritative production release gate** dan `scripts/deploy-production.sh` sebagai baseline deployment. `scripts/verify-release.sh` hanya compatibility alias yang mendelegasikan ke final gate. Jangan melewati failure pada gate atau pada `nadi:release-check --production`.
