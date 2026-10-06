<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceInvoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'amount' => 'float',
            'voided_at' => 'datetime',
        ];
    }

    public function payments()
    {
        return $this->hasMany(FinancePayment::class);
    }

    public function revenueRecord()
    {
        return $this->belongsTo(FinancialRecord::class, 'revenue_record_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
