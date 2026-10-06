<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function riskSignal() { return $this->belongsTo(RiskSignal::class); }
    public function actionItem() { return $this->belongsTo(ActionItem::class); }
    public function managementReview() { return $this->belongsTo(ManagementReview::class); }
}
