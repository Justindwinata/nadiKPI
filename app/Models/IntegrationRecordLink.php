<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationRecordLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function source()
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function lastBatch()
    {
        return $this->belongsTo(DataImportBatch::class, 'last_import_batch_id');
    }
}
