<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'city', 'status', 'monthly_capacity', 'verification_valid_until', 'evidence_reference'])]
class Tuk extends Model
{
    protected function casts(): array
    {
        return ['verification_valid_until' => 'date', 'archived_at' => 'datetime'];
    }

    public function batches()
    {
        return $this->hasMany(CertificationBatch::class);
    }
}
