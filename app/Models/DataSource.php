<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DataSource extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (DataSource $source): void {
            if ($source->importBatches()->exists() || $source->integrationProfiles()->exists() || $source->recordLinks()->exists()) {
                throw new LogicException('Data source with provenance/import evidence cannot be deleted. Deactivate it instead.');
            }
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'authority_rank' => 'integer'];
    }

    public function importBatches()
    {
        return $this->hasMany(DataImportBatch::class);
    }

    public function integrationProfiles()
    {
        return $this->hasMany(IntegrationProfile::class);
    }

    public function recordLinks()
    {
        return $this->hasMany(IntegrationRecordLink::class);
    }
}
