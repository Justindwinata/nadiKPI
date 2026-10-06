<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificationAppeal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'due_at' => 'datetime', 'decision_at' => 'datetime'];
    }

    public function batch()
    {
        return $this->belongsTo(CertificationBatch::class, 'certification_batch_id');
    }
}
