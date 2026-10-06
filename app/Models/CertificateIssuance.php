<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CertificateIssuance extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Certificate issuance ledger entries are immutable. Record a compensating business event instead of editing history.');
        });

        static::deleting(function (): never {
            throw new LogicException('Certificate issuance ledger entries are immutable and cannot be deleted.');
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'issued_count' => 'integer',
        ];
    }

    public function batch()
    {
        return $this->belongsTo(CertificationBatch::class, 'certification_batch_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
