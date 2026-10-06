<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RiskSignal extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Risk signal evidence cannot be hard-deleted. Resolve or dismiss it through the lifecycle instead.');
        });
    }
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'due_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'auto_escalated_at' => 'datetime',
            'escalation_level' => 'integer',
        ];
    }

    public function department() { return $this->belongsTo(Department::class); }
    public function kpi() { return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id'); }
    public function acknowledger() { return $this->belongsTo(User::class, 'acknowledged_by'); }
    public function escalator() { return $this->belongsTo(User::class, 'escalated_by'); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }
    public function actions() { return $this->hasMany(ActionItem::class); }
    public function reviewItems() { return $this->hasMany(ManagementReviewItem::class); }
    public function notifications() { return $this->hasMany(UserNotification::class); }
}
