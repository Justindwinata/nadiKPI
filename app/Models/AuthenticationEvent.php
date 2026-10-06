<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuthenticationEvent extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Authentication event entries are append-only and cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('Authentication event entries are append-only and cannot be deleted.');
        });
    }

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
