<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit log entries are append-only and cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('Audit log entries are append-only and cannot be deleted.');
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
