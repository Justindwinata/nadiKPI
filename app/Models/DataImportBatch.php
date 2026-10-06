<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DataImportBatch extends Model
{
    private const IMMUTABLE_IDENTITY_FIELDS = [
        'reference', 'data_source_id', 'dataset_type', 'dataset_name', 'file_name', 'sha256', 'imported_at', 'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (DataImportBatch $batch): void {
            foreach (self::IMMUTABLE_IDENTITY_FIELDS as $field) {
                if ($batch->isDirty($field)) {
                    throw new LogicException("Data import batch identity field {$field} is immutable.");
                }
            }
        });
        static::deleting(function (): void {
            throw new LogicException('Data import batches are provenance evidence and cannot be deleted.');
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'staged_at' => 'datetime',
            'published_at' => 'datetime',
            'errors' => 'array',
            'column_mapping' => 'array',
            'mapping_defaults' => 'array',
            'source_headers' => 'array',
            'validation_summary' => 'array',
        ];
    }

    public function source()
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function stagingRows()
    {
        return $this->hasMany(IntegrationStagingRow::class)->orderBy('row_number');
    }
}
