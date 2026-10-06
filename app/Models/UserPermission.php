<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['allowed' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function grantor()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
