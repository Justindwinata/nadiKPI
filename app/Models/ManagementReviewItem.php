<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ManagementReviewItem extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Management review item evidence cannot be hard-deleted.');
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    public function review()
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }

    public function signal()
    {
        return $this->belongsTo(RiskSignal::class, 'risk_signal_id');
    }

    public function action()
    {
        return $this->belongsTo(ActionItem::class, 'action_item_id');
    }
}
