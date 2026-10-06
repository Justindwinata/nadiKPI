<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['registration_no', 'name', 'specialization', 'status', 'valid_until'])]
class Assessor extends Model
{
    protected function casts(): array
    {
        return ['valid_until' => 'date', 'archived_at' => 'datetime'];
    }

    public function batches()
    {
        return $this->hasMany(CertificationBatch::class);
    }
}
