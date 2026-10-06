<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItIncident extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function service()
    {
        return $this->belongsTo(ItService::class, 'it_service_id');
    }
}
