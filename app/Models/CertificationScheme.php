<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'category', 'units_count', 'is_active', 'valid_until', 'evidence_reference'])]
class CertificationScheme extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'valid_until' => 'date', 'archived_at' => 'datetime'];
    }

    public function batches()
    {
        return $this->hasMany(CertificationBatch::class);
    }
}
