<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'kpi_definition_id', 'target', 'warning_threshold', 'weight', 'owner_name',
    'effective_from', 'effective_until', 'is_active', 'change_reason', 'created_by',
])]
class KpiConfiguration extends Model
{
    protected function casts(): array
    {
        return [
            'target' => 'float',
            'warning_threshold' => 'float',
            'weight' => 'float',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function kpi()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
