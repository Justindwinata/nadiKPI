<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataQualityRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
            'total_records' => 'integer',
            'valid_records' => 'integer',
            'missing_required_records' => 'integer',
            'duplicate_records' => 'integer',
            'freshness_failures' => 'integer',
        ];
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function qualityScore(): float
    {
        return $this->total_records > 0
            ? round($this->valid_records / $this->total_records * 100, 2)
            : 0.0;
    }
}
