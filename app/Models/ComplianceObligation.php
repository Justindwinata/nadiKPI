<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceObligation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date'];
    }
}
