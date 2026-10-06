<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorrectiveAction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function finding()
    {
        return $this->belongsTo(ComplianceFinding::class, 'compliance_finding_id');
    }
}
