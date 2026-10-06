<?php

namespace App\Console\Commands;

use App\Services\NotificationMonitoringService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class MonitorRisks extends Command
{
    protected $signature = 'nadi:monitor-risks {--at= : Waktu simulasi ISO-8601 untuk pengujian}';
    protected $description = 'Sinkronkan risk signal, eskalasi kritis, dan notifikasi operasional NADI.';

    public function handle(NotificationMonitoringService $monitor): int
    {
        try {
            $at = $this->option('at') ? Carbon::parse((string) $this->option('at')) : now();
            $result = $monitor->monitor($at);
            $this->info('NADI monitoring selesai.');
            foreach ($result as $key => $value) $this->line($key.': '.$value);
            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Monitoring gagal: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
