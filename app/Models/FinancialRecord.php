<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FinancialRecord extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Financial ledger records are immutable; use reversal workflows for corrections.');
        });

        static::deleting(function (): never {
            throw new LogicException('Financial ledger records are immutable and cannot be deleted.');
        });
    }

    protected $guarded = [];

    protected $appends = ['net_amount', 'is_reversed'];

    protected function casts(): array
    {
        return [
            'recorded_on' => 'date',
            'amount' => 'float',
        ];
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals()
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getNetAmountAttribute(): float
    {
        return ($this->entry_kind ?? 'normal') === 'reversal' ? -1 * (float) $this->amount : (float) $this->amount;
    }

    public function getIsReversedAttribute(): bool
    {
        return ($this->entry_kind ?? 'normal') === 'normal' && $this->relationLoaded('reversals')
            ? $this->reversals->isNotEmpty()
            : (($this->entry_kind ?? 'normal') === 'normal' && $this->reversals()->exists());
    }
}
