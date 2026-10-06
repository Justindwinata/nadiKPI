<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'defaults' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function source()
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }
}
