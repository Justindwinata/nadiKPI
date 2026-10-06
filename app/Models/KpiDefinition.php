<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class KpiDefinition extends Model
{
    public const SYSTEM_DERIVED_CODES = ['CERT-VOLUME', 'CERT-PASS', 'CERT-SLA', 'FIN-REV', 'FIN-MARGIN', 'FIN-BUDGET', 'IT-UPTIME', 'IT-MTTR', 'IT-DATA'];
    protected $guarded = [];

    protected function casts(): array
    {
        return ['target' => 'float', 'warning_threshold' => 'float', 'weight' => 'float', 'is_active' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function isSystemDerived(): bool
    {
        return $this->calculation_mode === 'system' || in_array($this->code, self::SYSTEM_DERIVED_CODES, true);
    }

    public function configurations()
    {
        return $this->hasMany(KpiConfiguration::class)->orderBy('effective_from');
    }

    public function configurationFor(CarbonInterface|string|null $date = null): ?KpiConfiguration
    {
        $date = $date ? (is_string($date) ? CarbonImmutable::parse($date) : $date) : now();
        $configs = $this->relationLoaded('configurations') ? $this->configurations : $this->configurations()->get();

        return $configs
            ->filter(fn (KpiConfiguration $config) => $config->effective_from->lte($date)
                && (! $config->effective_until || $config->effective_until->gte($date)))
            ->sortByDesc('effective_from')
            ->first();
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function measurements()
    {
        return $this->hasMany(KpiMeasurement::class);
    }

    public function actions()
    {
        return $this->hasMany(ActionItem::class);
    }
}
