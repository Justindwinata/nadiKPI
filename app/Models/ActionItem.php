<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ActionItem extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Action item evidence cannot be hard-deleted. Complete it through the lifecycle instead.');
        });
    }
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'escalated_at' => 'datetime',
            'escalation_level' => 'integer',
        ];
    }

    public function department() { return $this->belongsTo(Department::class); }
    public function kpi() { return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id'); }
    public function riskSignal() { return $this->belongsTo(RiskSignal::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function updater() { return $this->belongsTo(User::class, 'updated_by'); }
    public function acknowledger() { return $this->belongsTo(User::class, 'acknowledged_by'); }
    public function escalator() { return $this->belongsTo(User::class, 'escalated_by'); }
}
