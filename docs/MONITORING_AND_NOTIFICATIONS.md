# NADI Monitoring & Notification Operations

## Tujuan

Lapisan monitoring NADI membuat exception operasional tetap dipantau walaupun tidak ada pengguna yang sedang membuka dashboard. Scheduler menjalankan `nadi:monitor-risks`, menyinkronkan risk signal, melakukan auto-escalation untuk signal kritis yang belum diakui, membuat reminder action overdue, dan menghasilkan notifikasi per pengguna.

## Production scheduler

Gunakan salah satu pola berikut. Jangan menjalankan keduanya sekaligus.

### Cron Laravel scheduler

```cron
* * * * * cd /var/www/nadi && php artisan schedule:run >> /dev/null 2>&1
```

### Long-running scheduler worker

```bash
php artisan schedule:work
```

Jika menggunakan systemd/Supervisor, konfigurasikan restart otomatis dan logging sesuai standar server perusahaan.

Validasi deployment:

```bash
php artisan schedule:list
php artisan nadi:monitor-risks
```

## Critical escalation

Default:

```dotenv
NADI_CRITICAL_ESCALATE_LEVEL1_MINUTES=120
NADI_CRITICAL_ESCALATE_LEVEL2_MINUTES=480
```

Signal `critical` yang masih aktif dan belum mempunyai `acknowledged_at` akan dinaikkan ke level 1 setelah 120 menit dan level 2 setelah 480 menit. Escalation dilakukan oleh sistem (`escalated_by = null`) dan masuk audit trail sebagai `auto_escalate_risk_signal`.

Acknowledgement notifikasi **tidak** sama dengan acknowledgement risk signal. Tombol **Akui** pada Notification Center menyatakan notifikasi telah diterima pengguna; ownership risiko tetap harus diambil melalui Pusat Keputusan.

## Notification recipients

Notifikasi risk signal dikirim ke:

- Pimpinan dengan `decisions.view`;
- Kepala Mutu & Kepatuhan sebagai oversight;
- user aktif pada divisi pemilik signal yang memiliki `decisions.view`.

Critical escalation dapat diperluas ke user Quality yang mempunyai `decisions.view`. Authorization tetap dihitung dari effective permission backend, termasuk permission override.

## Reminder policy

Action item yang sudah melewati `due_date` menghasilkan satu reminder per hari per penerima. Management Review berstatus `draft`/`in_review` menghasilkan reminder ketika jadwal rapat masuk jendela 24 jam.

Notifikasi yang sudah dibaca dibersihkan setelah retention period:

```dotenv
NADI_NOTIFICATION_RETENTION_DAYS=180
```

Audit log operasional tidak ikut dihapus oleh retention Notification Center.

## Realtime UI via SSE

Browser membuka koneksi session-authenticated:

```text
GET /api/notifications/stream
```

Server menggunakan Server-Sent Events (SSE) dan mengirim event `notification`. Koneksi dirotasi secara periodik agar tidak menjadi request tak terbatas; EventSource browser melakukan reconnect dan mengirim `Last-Event-ID`, sehingga event lama tidak dikirim ulang.

Default stream window:

```dotenv
NADI_SSE_STREAM_SECONDS=20
```

Jika SSE terputus, frontend mempertahankan fallback refresh Notification Center setiap 60 detik.

### Reverse proxy

Untuk Nginx, endpoint SSE harus menghindari buffering. Response aplikasi sudah mengirim `X-Accel-Buffering: no`; pastikan proxy tidak mengoverride header tersebut. Gunakan HTTPS pada production.

SSE cocok untuk jumlah user internal LSP yang moderat. Jika deployment berkembang menjadi ratusan/ribuan koneksi concurrent, migrasikan delivery transport ke Laravel Reverb/WebSocket atau message broker tanpa mengubah `user_notifications` sebagai inbox sumber.

## Notification API

```text
GET  /api/notifications
GET  /api/notifications/stream
POST /api/notifications/read-all
POST /api/notifications/{notification}/read
POST /api/notifications/{notification}/acknowledge
```

User hanya dapat membaca/mengubah notifikasi miliknya sendiri.

## Health checks

Pemeriksaan rutin:

```bash
php artisan nadi:monitor-risks
php artisan schedule:list
```

Pantau application log untuk kegagalan scheduler dan pastikan cron/Supervisor aktif. Jika risk signal terbuat tetapi Notification Center tidak berubah, periksa scheduler, database, session authentication, reverse-proxy buffering, lalu fallback endpoint `GET /api/notifications`.
