<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReportSnapshot extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Report snapshots are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Report snapshots are immutable and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
            'payload' => 'array',
            'source_manifest' => 'array',
            'schema_version' => 'integer',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function managementReview()
    {
        return $this->belongsTo(ManagementReview::class);
    }

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
