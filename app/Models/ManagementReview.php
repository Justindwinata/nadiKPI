<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ManagementReview extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Management review evidence cannot be hard-deleted. Close it through the lifecycle instead.');
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'meeting_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(ManagementReviewItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
