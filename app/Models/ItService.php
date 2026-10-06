<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'owner', 'target_uptime', 'monitoring_started_at', 'status'])]
class ItService extends Model
{
    protected function casts(): array
    {
        return ['target_uptime' => 'float', 'monitoring_started_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function incidents()
    {
        return $this->hasMany(ItIncident::class);
    }
}
