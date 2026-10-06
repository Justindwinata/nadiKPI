<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancePayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'float',
            'reversed_at' => 'datetime',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(FinanceInvoice::class, 'finance_invoice_id');
    }
}
