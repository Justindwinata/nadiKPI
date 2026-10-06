<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ComplianceFinding extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (ComplianceFinding $finding): void {
            if ($finding->actions()->exists()) {
                throw new LogicException('Compliance finding with CAPA evidence cannot be deleted.');
            }
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'due_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function actions()
    {
        return $this->hasMany(CorrectiveAction::class);
    }
}
