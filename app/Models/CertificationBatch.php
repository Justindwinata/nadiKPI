<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CertificationBatch extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (CertificationBatch $batch): void {
            if ($batch->issuances()->exists() || $batch->appeals()->exists()) {
                throw new LogicException('Certification batch with issuance/appeal evidence cannot be deleted.');
            }
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'assessment_completed_at' => 'datetime',
            'decision_at' => 'datetime',
            'certificate_due_at' => 'datetime',
            'completed_at' => 'datetime',
            'revenue' => 'float',
            'total_assesi' => 'integer',
            'passed' => 'integer',
            'failed' => 'integer',
            'pending' => 'integer',
            'certificates_issued' => 'integer',
            'issued_on_time' => 'integer',
        ];
    }

    public function scheme()
    {
        return $this->belongsTo(CertificationScheme::class, 'certification_scheme_id');
    }

    public function tuk()
    {
        return $this->belongsTo(Tuk::class);
    }

    public function assessor()
    {
        return $this->belongsTo(Assessor::class);
    }

    public function issuances()
    {
        return $this->hasMany(CertificateIssuance::class)->orderBy('issued_at');
    }

    public function appeals()
    {
        return $this->hasMany(CertificationAppeal::class);
    }
}
