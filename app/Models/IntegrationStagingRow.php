<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationStagingRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'validation_errors' => 'array',
        ];
    }

    public function batch()
    {
        return $this->belongsTo(DataImportBatch::class, 'data_import_batch_id');
    }
}
