<?php

namespace Database\Seeders;

use App\Models\ActionItem;
use App\Models\Assessor;
use App\Models\CertificateIssuance;
use App\Models\CertificationBatch;
use App\Models\CertificationAppeal;
use App\Models\CertificationScheme;
use App\Models\Department;
use App\Models\DataQualityRun;
use App\Models\DataSource;
use App\Models\DataImportBatch;
use App\Models\ComplianceFinding;
use App\Models\ComplianceObligation;
use App\Models\CorrectiveAction;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancialRecord;
use App\Models\ItIncident;
use App\Models\ItService;
use App\Models\IntegrationProfile;
use App\Models\KpiDefinition;
use App\Models\KpiConfiguration;
use App\Models\KpiMeasurement;
use App\Models\RiskSignal;
use App\Models\ManagementReview;
use App\Models\ManagementReviewItem;
use App\Models\Tuk;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PrototypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->environment('production') && ! config('nadi.allow_demo_seed')) {
            throw new \RuntimeException('PrototypeSeeder diblokir di production. Gunakan nadi:create-admin dan onboarding data nyata.');
        }

        $demoPassword = trim((string) config('nadi.demo_password'));
        if ($demoPassword === '') {
            throw new \RuntimeException('NADI_DEMO_PASSWORD wajib diisi sebelum menjalankan PrototypeSeeder.');
        }

        mt_srand(74074);

        $departments = collect([
            ['code' => 'leadership', 'name' => 'Pimpinan', 'color' => '#e9b949', 'description' => 'Kendali strategi, kepatuhan, dan keputusan lintas fungsi.'],
            ['code' => 'certification', 'name' => 'Sertifikasi', 'color' => '#2dd4bf', 'description' => 'Operasional skema, asesi, asesor, TUK, dan penerbitan sertifikat.'],
            ['code' => 'finance', 'name' => 'Keuangan', 'color' => '#7dd3fc', 'description' => 'Pendapatan, biaya, anggaran, dan kesehatan arus operasi.'],
            ['code' => 'it', 'name' => 'Teknologi Informasi', 'color' => '#c4b5fd', 'description' => 'Ketersediaan layanan, insiden, keamanan, dan mutu data.'],
            ['code' => 'quality', 'name' => 'Mutu & Kepatuhan', 'color' => '#f0abfc', 'description' => 'Temuan, tindakan korektif, banding, validitas, provenance, dan kepatuhan.'],
        ])->mapWithKeys(fn ($data) => [$data['code'] => Department::create($data)]);

        $users = collect([
            ['name' => 'Arif Rahman', 'email' => 'pimpinan@demo.test', 'role' => 'director', 'position' => 'Direktur LSP', 'department_id' => $departments['leadership']->id],
            ['name' => 'Sinta Prameswari', 'email' => 'sertifikasi@demo.test', 'role' => 'department_head', 'position' => 'Kepala Sertifikasi', 'department_id' => $departments['certification']->id],
            ['name' => 'Dimas Wicaksono', 'email' => 'keuangan@demo.test', 'role' => 'department_head', 'position' => 'Kepala Keuangan', 'department_id' => $departments['finance']->id],
            ['name' => 'Nadia Putri', 'email' => 'it@demo.test', 'role' => 'department_head', 'position' => 'Kepala TI', 'department_id' => $departments['it']->id],
            ['name' => 'Ratna Lestari', 'email' => 'mutu@demo.test', 'role' => 'department_head', 'position' => 'Kepala Mutu & Kepatuhan', 'department_id' => $departments['quality']->id],
        ])->map(fn ($data) => User::create($data + ['password' => Hash::make($demoPassword), 'is_active' => true, 'must_change_password' => false, 'password_changed_at' => now()]));

        $kpiRows = [
            ['certification', 'CERT-VOLUME', 'Volume Asesi', 'Jumlah peserta yang mengikuti asesmen pada periode berjalan.', 'asesi', 'higher', 14, 250, 225, 'Batch asesmen'],
            ['certification', 'CERT-PASS', 'Tingkat Kompeten', 'Persentase asesi dengan keputusan kompeten.', '%', 'higher', 12, 92, 88, 'Keputusan sertifikasi'],
            ['certification', 'CERT-SLA', 'Ketepatan Sertifikat', 'Sertifikat terbit maksimal 30 hari setelah proses selesai.', '%', 'higher', 12, 95, 90, 'Register penerbitan'],
            ['finance', 'FIN-REV', 'Pendapatan Operasional', 'Pendapatan jasa sertifikasi per bulan.', 'juta', 'higher', 13, 1150, 1000, 'Buku besar'],
            ['finance', 'FIN-MARGIN', 'Margin Operasional', 'Selisih pendapatan dan beban dibanding pendapatan.', '%', 'higher', 10, 28, 24, 'Buku besar'],
            ['finance', 'FIN-BUDGET', 'Deviasi Anggaran', 'Selisih realisasi biaya terhadap rencana anggaran.', '%', 'lower', 9, 5, 8, 'Anggaran dan realisasi'],
            ['it', 'IT-UPTIME', 'Ketersediaan Sistem', 'Persentase waktu layanan digital tersedia.', '%', 'higher', 12, 99.5, 99, 'Monitoring layanan'],
            ['it', 'IT-MTTR', 'Waktu Pemulihan', 'Rata-rata jam penyelesaian insiden layanan.', 'jam', 'lower', 8, 4, 6, 'Log insiden'],
            ['it', 'IT-DATA', 'Kelengkapan Data', 'Rekam data wajib yang lengkap dan lolos validasi.', '%', 'higher', 10, 98, 95, 'Audit kualitas data'],
        ];
        $kpis = collect($kpiRows)->map(fn ($row) => KpiDefinition::create([
            'department_id' => $departments[$row[0]]->id, 'code' => $row[1], 'name' => $row[2],
            'description' => $row[3], 'unit' => $row[4], 'direction' => $row[5], 'weight' => $row[6],
            'target' => $row[7], 'warning_threshold' => $row[8], 'data_source' => $row[9], 'cadence' => 'monthly',
            'calculation_mode' => 'system', 'created_by' => $users->first()->id,
        ]));

        foreach ($kpis as $kpi) {
            $owner = match (true) {
                str_starts_with($kpi->code, 'CERT-') => 'Kepala Sertifikasi',
                str_starts_with($kpi->code, 'FIN-') => 'Kepala Keuangan',
                str_starts_with($kpi->code, 'IT-') => 'Kepala TI',
                default => 'Pimpinan',
            };
            KpiConfiguration::create([
                'kpi_definition_id' => $kpi->id,
                'target' => $kpi->target,
                'warning_threshold' => $kpi->warning_threshold,
                'weight' => $kpi->weight,
                'owner_name' => $owner,
                'effective_from' => now()->startOfYear()->toDateString(),
                'is_active' => true,
                'change_reason' => 'Baseline target prototipe untuk demonstrasi.',
                'created_by' => $users->first()->id,
            ]);
        }

        $patterns = [
            'IT-UPTIME' => [98.8, 99.1, 99.3, 99.6, 99.7, 99.4, 99.8, 99.9, 99.6, 99.7, 99.5, 99.2],
            'IT-MTTR' => [8.2, 7.4, 6.8, 5.9, 4.7, 4.3, 3.8, 3.5, 4.1, 3.7, 4.8, 5.6],
            'IT-DATA' => [91, 92, 93.5, 94, 95.2, 96.1, 96.8, 97.4, 97.9, 98.2, 97.6, 96.9],
        ];
        foreach ($kpis as $kpi) {
            if ($kpi->isSystemDerived()) {
                continue;
            }
            foreach ($patterns[$kpi->code] as $index => $actual) {
                KpiMeasurement::create(['kpi_definition_id' => $kpi->id, 'period' => now()->startOfMonth()->subMonths(11 - $index), 'actual' => $actual, 'target_snapshot' => $kpi->target, 'notes' => 'Data prototipe sintetis untuk demonstrasi.', 'recorded_by' => $users->first()->id]);
            }
        }

        $schemes = collect([
            ['SK-K3-01', 'Operator K3 Migas', 9], ['SK-WLD-01', 'Welding Supervisor', 16],
            ['SK-INS-01', 'Inspektur Bejana Tekan', 10], ['SK-RIG-01', 'Juru Ikat Beban', 8],
            ['SK-PRD-01', 'Operator Produksi', 15], ['SK-ENE-01', 'Auditor Energi Industri', 5],
        ])->map(fn ($row, $index) => CertificationScheme::create(['code' => $row[0], 'name' => $row[1], 'category' => 'Okupasi', 'units_count' => $row[2], 'valid_until' => now()->addMonths($index === 0 ? 2 : 18), 'evidence_reference' => 'Register skema internal']));

        $tuks = collect([
            ['TUK-JKT-01', 'TUK Mandiri Jakarta', 'Jakarta', 180], ['TUK-BKS-01', 'TUK Sewaktu Bekasi', 'Bekasi', 120],
            ['TUK-PLB-01', 'TUK Tempat Kerja Palembang', 'Palembang', 100], ['TUK-BPN-01', 'TUK Tempat Kerja Balikpapan', 'Balikpapan', 120],
        ])->map(fn ($row, $index) => Tuk::create(['code' => $row[0], 'name' => $row[1], 'city' => $row[2], 'monthly_capacity' => $row[3], 'verification_valid_until' => now()->addMonths($index === 1 ? 1 : 12), 'evidence_reference' => 'Register verifikasi TUK internal']));

        $assessors = collect(['Rizal Mahendra', 'Maya Kusuma', 'Bambang Setiawan', 'Intan Larasati', 'Fajar Pratama'])
            ->map(fn ($name, $i) => Assessor::create(['registration_no' => 'MET.'.str_pad((string) (7400 + $i), 6, '0', STR_PAD_LEFT), 'name' => $name, 'specialization' => $schemes[$i % $schemes->count()]->name, 'status' => $i === 4 ? 'expiring' : 'active', 'valid_until' => now()->addMonths($i === 4 ? 2 : 18)]));

        for ($month = 11; $month >= 0; $month--) {
            for ($n = 1; $n <= 2; $n++) {
                $date = now()->startOfMonth()->subMonths($month)->addDays(7 + ($n * 8));
                $total = 92 + (($month * 13 + $n * 17) % 48);
                $decided = $month > 0 || ($month === 0 && $n === 1);
                $completed = $month > 0;
                $passed = $decided ? (int) round($total * (0.89 + (($month + $n) % 5) / 100)) : 0;
                $failed = $decided ? $total - $passed : 0;
                $assessmentCompletedAt = $decided ? $date->copy()->addDay()->setTime(16, 0) : null;
                $decisionAt = $decided ? $date->copy()->addDays(3)->setTime(10, 0) : null;
                $certificateDueAt = $decisionAt?->copy()->addDays(30);
                $onTimeCount = $completed ? max(0, $passed - (($month + $n) % 7)) : 0;
                $lateCount = $completed ? $passed - $onTimeCount : 0;
                $issued = $completed ? $passed : 0;
                $batch = CertificationBatch::create([
                    'code' => 'ASM-'.$date->format('Ym').'-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                    'certification_scheme_id' => $schemes[($month + $n) % $schemes->count()]->id,
                    'tuk_id' => $tuks[($month + $n) % $tuks->count()]->id,
                    'assessor_id' => $assessors[($month + $n) % $assessors->count()]->id,
                    'assessment_date' => $date,
                    'assessment_completed_at' => $assessmentCompletedAt,
                    'decision_at' => $decisionAt,
                    'certificate_due_at' => $certificateDueAt,
                    'completed_at' => $completed ? $certificateDueAt?->copy()->addDays(5) : null,
                    'total_assesi' => $total,
                    'passed' => $passed,
                    'failed' => $failed,
                    'pending' => $decided ? 0 : $total,
                    'certificates_issued' => $issued,
                    'issued_on_time' => $onTimeCount,
                    'revenue' => $total * 4_250_000,
                    'status' => $completed ? 'completed' : ($n === 1 ? 'decision' : 'assessment'),
                ]);

                if ($completed && $onTimeCount > 0) {
                    CertificateIssuance::create([
                        'certification_batch_id' => $batch->id,
                        'issued_count' => $onTimeCount,
                        'issued_at' => $decisionAt->copy()->addDays(20),
                        'reference' => 'CERT-'.$batch->code.'-ONTIME',
                        'notes' => 'Data prototipe: penerbitan dalam SLA 30 hari.',
                        'recorded_by' => $users->first()->id,
                    ]);
                }
                if ($completed && $lateCount > 0) {
                    CertificateIssuance::create([
                        'certification_batch_id' => $batch->id,
                        'issued_count' => $lateCount,
                        'issued_at' => $certificateDueAt->copy()->addDays(5),
                        'reference' => 'CERT-'.$batch->code.'-LATE',
                        'notes' => 'Data prototipe: penerbitan melewati SLA 30 hari.',
                        'recorded_by' => $users->first()->id,
                    ]);
                }
            }

            $date = now()->startOfMonth()->subMonths($month)->addDays(15);
            $revenue = 920_000_000 + ((11 - $month) * 31_000_000) + (($month % 3) * 42_000_000);
            $budget = 770_000_000 + (($month % 4) * 18_000_000);
            $expense = (int) ($budget * (0.90 + (($month % 5) / 100)));

            $invoice = FinanceInvoice::create([
                'invoice_number' => 'INV-'.$date->format('Ym').'-001',
                'issued_on' => $date,
                'due_on' => $date->copy()->addDays(30),
                'customer_name' => ['PT Energi Nusantara', 'PT Petro Teknik', 'PT Karya Migas'][$month % 3],
                'category' => 'Jasa sertifikasi',
                'department_code' => 'finance',
                'amount' => $revenue,
                'description' => 'Tagihan layanan asesmen dan sertifikasi',
                'status' => $month >= 2 ? 'paid' : ($month === 1 ? 'partially_paid' : 'issued'),
                'created_by' => $users[2]->id,
            ]);
            $revenueRecord = FinancialRecord::create([
                'recorded_on' => $date,
                'type' => 'revenue',
                'entry_kind' => 'normal',
                'category' => 'Jasa sertifikasi',
                'department_code' => 'finance',
                'amount' => $revenue,
                'description' => 'Pendapatan layanan asesmen dan sertifikasi',
                'reference' => 'INV-'.$invoice->invoice_number,
                'source_type' => 'finance_invoice',
                'source_id' => $invoice->id,
                'recorded_by' => $users[2]->id,
            ]);
            $invoice->update(['revenue_record_id' => $revenueRecord->id]);

            if ($month >= 2) {
                FinancePayment::create([
                    'finance_invoice_id' => $invoice->id,
                    'paid_on' => $date->copy()->addDays(20),
                    'amount' => $revenue,
                    'reference' => 'PAY-'.$date->format('Ym').'-001',
                    'notes' => 'Pelunasan data prototipe.',
                    'recorded_by' => $users[2]->id,
                ]);
            } elseif ($month === 1) {
                FinancePayment::create([
                    'finance_invoice_id' => $invoice->id,
                    'paid_on' => $date->copy()->addDays(12),
                    'amount' => round($revenue * 0.70),
                    'reference' => 'PAY-'.$date->format('Ym').'-001',
                    'notes' => 'Pembayaran parsial data prototipe.',
                    'recorded_by' => $users[2]->id,
                ]);
            }

            FinancialRecord::create([
                'recorded_on' => $date,
                'type' => 'budget',
                'entry_kind' => 'normal',
                'category' => 'Anggaran operasional',
                'department_code' => 'finance',
                'amount' => $budget,
                'description' => 'Anggaran operasional periode berjalan',
                'reference' => 'B-'.$date->format('Ym').'-01',
                'source_type' => 'manual',
                'recorded_by' => $users[2]->id,
            ]);
            FinancialRecord::create([
                'recorded_on' => $date,
                'type' => 'expense',
                'entry_kind' => 'normal',
                'category' => ['Honor asesor', 'Operasional TUK', 'Administrasi'][$month % 3],
                'department_code' => 'certification',
                'amount' => $expense,
                'description' => 'Realisasi biaya kegiatan sertifikasi',
                'reference' => 'E-'.$date->format('Ym').'-01',
                'source_type' => 'manual',
                'recorded_by' => $users[2]->id,
            ]);
        }

        foreach ($patterns['IT-DATA'] as $index => $qualityScore) {
            $assessedAt = now()->startOfMonth()->subMonths(11 - $index)->addDays(20)->setTime(15, 0);
            if ($assessedAt->isFuture()) {
                $assessedAt = now()->subHours(2);
            }
            $totalRecords = 10000;
            $validRecords = (int) round($totalRecords * $qualityScore / 100);
            $invalidRecords = $totalRecords - $validRecords;
            DataQualityRun::create([
                'reference' => 'DQ-'.$assessedAt->format('Ym').'-01',
                'dataset_name' => 'Dataset Operasional Sertifikasi',
                'source_system' => 'NADI Operational Store',
                'assessed_at' => $assessedAt,
                'total_records' => $totalRecords,
                'valid_records' => $validRecords,
                'missing_required_records' => (int) floor($invalidRecords * 0.55),
                'duplicate_records' => (int) floor($invalidRecords * 0.25),
                'freshness_failures' => max(0, $invalidRecords - (int) floor($invalidRecords * 0.55) - (int) floor($invalidRecords * 0.25)),
                'notes' => 'Audit kualitas data sintetis untuk demonstrasi prototipe.',
                'recorded_by' => $users[3]->id,
            ]);
        }

        $services = collect([
            ['Portal Sertifikasi', 'Tim Aplikasi', 99.7, 'operational'], ['Database Operasional', 'Tim Infrastruktur', 99.9, 'operational'],
            ['Integrasi Dokumen', 'Tim Aplikasi', 99.5, 'degraded'], ['Jaringan Kantor', 'Tim Infrastruktur', 99.5, 'operational'],
        ])->map(fn ($row) => ItService::create(['name' => $row[0], 'owner' => $row[1], 'target_uptime' => $row[2], 'status' => $row[3]]));
        for ($i = 0; $i < 14; $i++) {
            $started = $i === 0 ? now()->subHours(3) : now()->subDays(18 + ($i * 21))->setTime(8 + ($i % 8), 15);
            ItIncident::create([
                'it_service_id' => $services[$i % $services->count()]->id, 'reference' => 'INC-'.now()->format('Y').'-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'severity' => ['low', 'medium', 'high', 'critical'][$i % 4], 'status' => $i === 0 ? 'investigating' : 'resolved', 'started_at' => $started,
                'acknowledged_at' => $started->copy()->addMinutes(12 + ($i % 20)),
                'resolved_at' => $i === 0 ? null : $started->copy()->addHours(1 + (($i * 3) % 9)),
                'resolved_by' => $i === 0 ? null : $users[3]->id,
                'summary' => ['Antrean unggah dokumen meningkat', 'Sinkronisasi data tertunda', 'Koneksi TUK tidak stabil', 'Layanan autentikasi mengalami gangguan'][$i % 4],
                'resolution_summary' => $i === 0 ? null : 'Layanan dipulihkan dan verifikasi kesehatan pascainsiden selesai.',
                'root_cause' => $i === 0 ? null : ['Lonjakan antrean proses', 'Kegagalan sinkronisasi terjadwal', 'Gangguan konektivitas', 'Konfigurasi layanan autentikasi'][$i % 4],
            ]);
        }

        $byCode = $kpis->keyBy('code');
        $internalSource = DataSource::create([
            'code' => 'SRC-NADI-OPS',
            'name' => 'NADI Operational Store',
            'source_type' => 'internal',
            'authority_rank' => 100,
            'owner_name' => 'Tim Data & TI',
            'location' => 'Database operasional internal',
            'notes' => 'Sumber utama transaksi operasional prototype.',
        ]);
        DataSource::create([
            'code' => 'SRC-BNSP-REF',
            'name' => 'Referensi Regulasi BNSP',
            'source_type' => 'regulatory',
            'authority_rank' => 95,
            'owner_name' => 'Mutu & Kepatuhan',
            'location' => 'Referensi eksternal terverifikasi',
            'notes' => 'Digunakan sebagai sumber referensi/reconciliation, bukan pengganti data transaksi internal.',
        ]);
        $manualSource = DataSource::create([
            'code' => 'SRC-MANUAL',
            'name' => 'Input Manual Terkendali',
            'source_type' => 'manual',
            'authority_rank' => 30,
            'owner_name' => 'Pemilik proses',
            'notes' => 'Membutuhkan bukti dan rekonsiliasi bila digunakan sebagai sumber KPI custom.',
        ]);
        $legacyCertificationSource = DataSource::create([
            'code' => 'SRC-LEGACY-CERT',
            'name' => 'Legacy Certification Export',
            'source_type' => 'internal',
            'authority_rank' => 85,
            'owner_name' => 'Sertifikasi & TI',
            'location' => 'Ekspor sistem sertifikasi lama',
            'notes' => 'Contoh sumber onboarding data nyata; ganti dengan sistem sumber perusahaan saat implementasi.',
        ]);
        IntegrationProfile::create([
            'data_source_id' => $legacyCertificationSource->id,
            'dataset_type' => 'tuks',
            'name' => 'Mapping TUK Legacy',
            'column_mapping' => [
                'code' => 'kode_tuk',
                'name' => 'nama_tuk',
                'city' => 'kota',
                'monthly_capacity' => 'kapasitas_bulanan',
                'status' => 'status',
                'verification_valid_until' => 'berlaku_sampai',
                'evidence_reference' => 'referensi_bukti',
            ],
            'defaults' => [],
            'is_active' => true,
            'created_by' => $users[4]->id,
        ]);

        DataImportBatch::create([
            'reference' => 'IMP-DEMO-'.now()->format('Ym').'-001',
            'data_source_id' => $internalSource->id,
            'dataset_name' => 'KPI Custom Demonstrasi',
            'file_name' => 'kpi-custom-demo.csv',
            'sha256' => hash('sha256', 'nadi-demo-import-'.now()->format('Ym')),
            'imported_at' => now()->subDays(2),
            'total_rows' => 12,
            'accepted_rows' => 12,
            'rejected_rows' => 0,
            'status' => 'completed',
            'reconciliation_status' => 'reconciled',
            'created_by' => $users[4]->id,
        ]);

        ComplianceObligation::create([
            'code' => 'GOV-LICENSE-DEMO',
            'title' => 'Pemantauan masa berlaku lisensi dan ruang lingkup LSP',
            'category' => 'license',
            'authority' => 'Register kepatuhan internal',
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addMonths(8),
            'status' => 'active',
            'owner_name' => 'Ratna Lestari',
            'evidence_reference' => 'Dokumen lisensi/keputusan yang berlaku',
            'notes' => 'Contoh register kewajiban untuk demonstrasi; ganti dengan dokumen resmi perusahaan saat onboarding data real.',
            'created_by' => $users[4]->id,
        ]);

        $finding = ComplianceFinding::create([
            'reference' => 'FND-'.now()->format('Y').'-001',
            'source' => 'Audit internal triwulan',
            'category' => 'Data & dokumentasi',
            'severity' => 'high',
            'title' => 'Bukti verifikasi TUK perlu diperbarui',
            'description' => 'Satu TUK mendekati masa akhir verifikasi dan evidence terbaru belum terlampir pada register.',
            'status' => 'in_progress',
            'owner_name' => 'Sinta Prameswari',
            'opened_at' => now()->subDays(12),
            'due_at' => now()->addDays(10),
            'created_by' => $users[4]->id,
        ]);
        CorrectiveAction::create([
            'compliance_finding_id' => $finding->id,
            'title' => 'Validasi ulang evidence dan jadwal verifikasi TUK',
            'description' => 'Konfirmasi dokumen verifikasi, pemilik, dan rencana pembaruan sebelum jatuh tempo.',
            'owner_name' => 'Sinta Prameswari',
            'due_date' => today()->addDays(7),
            'status' => 'in_progress',
            'created_by' => $users[4]->id,
        ]);

        $appealBatch = CertificationBatch::query()->where('status', 'completed')->latest('assessment_date')->first();
        if ($appealBatch) {
            CertificationAppeal::create([
                'certification_batch_id' => $appealBatch->id,
                'reference' => 'APL-'.now()->format('Y').'-001',
                'appellant_reference' => 'ASESI-DEMO-001',
                'received_at' => now()->subDays(4),
                'due_at' => now()->addDays(10),
                'reason' => 'Permohonan peninjauan atas hasil asesmen pada salah satu unit kompetensi.',
                'status' => 'reviewing',
                'owner_name' => 'Ratna Lestari',
                'created_by' => $users[4]->id,
            ]);
        }

        foreach ([
            ['certification', 'CERT-SLA', 'Pulihkan SLA penerbitan sertifikat', 'Petakan berkas tertahan dan susun jalur cepat untuk batch yang melewati 21 hari.', 'critical', 'Sinta Prameswari', 5, 'in_progress'],
            ['it', 'IT-MTTR', 'Tuntaskan akar masalah integrasi dokumen', 'Lakukan RCA dan pasang alert untuk antrean unggah lebih dari lima menit.', 'high', 'Nadia Putri', 9, 'open'],
            ['finance', 'FIN-BUDGET', 'Tinjau biaya operasional TUK', 'Bandingkan biaya per asesi antar-TUK dan negosiasikan komponen dengan deviasi terbesar.', 'medium', 'Dimas Wicaksono', 14, 'open'],
        ] as $row) {
            ActionItem::create(['department_id' => $departments[$row[0]]->id, 'kpi_definition_id' => $byCode[$row[1]]->id, 'title' => $row[2], 'description' => $row[3], 'priority' => $row[4], 'owner_name' => $row[5], 'due_date' => today()->addDays($row[6]), 'status' => $row[7], 'created_by' => $users->first()->id]);
        }


        $historicalSignal = RiskSignal::create([
            'fingerprint' => 'demo:management-review:historical',
            'rule_code' => 'HISTORICAL_REVIEW',
            'category' => 'management',
            'severity' => 'high',
            'status' => 'resolved',
            'department_id' => $departments['certification']->id,
            'kpi_definition_id' => $byCode['CERT-SLA']->id,
            'source_type' => 'prototype_history',
            'source_reference' => 'MR-DEMO-HIST',
            'title' => 'Backlog penerbitan sertifikat periode sebelumnya',
            'description' => 'Contoh signal historis untuk menunjukkan jejak management review yang sudah ditutup.',
            'detected_at' => now()->subMonth()->startOfMonth()->addDays(8),
            'last_observed_at' => now()->subMonth()->addDays(4),
            'acknowledged_at' => now()->subMonth()->startOfMonth()->addDays(9),
            'acknowledged_by' => $users->first()->id,
            'resolved_at' => now()->subMonth()->addDays(5),
            'resolved_by' => $users->first()->id,
            'resolution_note' => 'Backlog dipulihkan melalui prioritas issuance dan rekonsiliasi evidence.',
        ]);
        $historicalReview = ManagementReview::create([
            'reference' => 'MR-DEMO-'.now()->subMonth()->format('Ym'),
            'title' => 'Management Review Bulanan — Demo Historis',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'meeting_at' => now()->subMonth()->endOfMonth()->setTime(10, 0),
            'chair_name' => 'Arif Rahman',
            'status' => 'closed',
            'summary' => 'Review kinerja lintas fungsi dan exception prioritas pada periode sebelumnya.',
            'decisions' => 'Prioritaskan backlog issuance, pantau recovery mingguan, dan verifikasi evidence sebelum closure.',
            'created_by' => $users->first()->id,
            'approved_by' => $users->first()->id,
            'approved_at' => now()->subMonth()->endOfMonth()->setTime(12, 0),
            'closed_at' => now()->subMonth()->endOfMonth()->setTime(15, 0),
        ]);
        ManagementReviewItem::create([
            'management_review_id' => $historicalReview->id,
            'risk_signal_id' => $historicalSignal->id,
            'title' => $historicalSignal->title,
            'owner_name' => 'Sinta Prameswari',
            'due_date' => now()->subMonth()->endOfMonth(),
            'decision' => 'Jalankan jalur cepat issuance dan review status setiap tiga hari sampai backlog nol.',
            'status' => 'completed',
        ]);
    }
}
