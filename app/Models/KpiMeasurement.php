<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiMeasurement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['period' => 'date', 'actual' => 'float', 'target_snapshot' => 'float'];
    }

    public function definition()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function importBatch()
    {
        return $this->belongsTo(DataImportBatch::class, 'data_import_batch_id');
    }
}
