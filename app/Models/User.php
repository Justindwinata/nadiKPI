<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;

#[Fillable([
    'name', 'email', 'password', 'department_id', 'role', 'position', 'is_active',
    'must_change_password', 'password_changed_at', 'last_login_at', 'last_login_ip',
    'deactivated_at', 'deactivated_by',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('User identities cannot be hard-deleted. Deactivate the account instead.');
        });
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const PERMISSIONS = [
        'overview.view',
        'certification.view',
        'certification.manage',
        'certification.issue',
        'finance.view',
        'finance.ledger.manage',
        'finance.invoices.manage',
        'finance.payments.manage',
        'finance.reverse',
        'it.view',
        'it.incidents.manage',
        'it.data_quality.manage',
        'governance.view',
        'governance.findings.manage',
        'governance.appeals.manage',
        'governance.registry.manage',
        'governance.provenance.manage',
        'governance.audit.view',
        'master_data.certification.manage',
        'master_data.it.manage',
        'kpi.catalog.view',
        'kpi.catalog.manage',
        'kpi.custom.manage',
        'actions.manage',
        'decisions.view',
        'decisions.manage',
        'decisions.review',
        'data.import.manage',
        'integrations.view',
        'integrations.manage',
        'reports.view',
        'reports.export',
        'users.manage',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function permissionOverrides()
    {
        return $this->hasMany(UserPermission::class);
    }

    public function nadiNotifications()
    {
        return $this->hasMany(UserNotification::class);
    }

    public function deactivator()
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function defaultPermissions(): array
    {
        if ($this->role === 'director') {
            return array_fill_keys(self::PERMISSIONS, true);
        }

        $permissions = ['overview.view' => true, 'kpi.catalog.view' => true, 'reports.view' => true];
        $department = $this->department?->code;
        $isHead = $this->role === 'department_head';
        $isAnalyst = $this->role === 'analyst';

        if ($department === 'certification') {
            $permissions['certification.view'] = true;
            if ($isHead || $isAnalyst) {
                $permissions['certification.manage'] = true;
            }
            if ($isHead) {
                $permissions['certification.issue'] = true;
                $permissions['master_data.certification.manage'] = true;
                $permissions['governance.appeals.manage'] = true;
            }
        }

        if ($department === 'finance') {
            $permissions['finance.view'] = true;
            if ($isHead || $isAnalyst) {
                $permissions['finance.ledger.manage'] = true;
                $permissions['finance.invoices.manage'] = true;
                $permissions['finance.payments.manage'] = true;
            }
            if ($isHead) {
                $permissions['finance.reverse'] = true;
            }
        }

        if ($department === 'it') {
            $permissions['it.view'] = true;
            if ($isHead || $isAnalyst) {
                $permissions['it.incidents.manage'] = true;
                $permissions['it.data_quality.manage'] = true;
            }
            if ($isHead) {
                $permissions['master_data.it.manage'] = true;
            }
        }

        if ($department === 'quality') {
            $permissions['governance.view'] = true;
            $permissions['decisions.view'] = true;
            if ($isHead || $isAnalyst) {
                $permissions['governance.findings.manage'] = true;
            }
            if ($isHead) {
                $permissions['decisions.manage'] = true;
                $permissions['decisions.review'] = true;
                $permissions['governance.appeals.manage'] = true;
                $permissions['governance.registry.manage'] = true;
                $permissions['governance.provenance.manage'] = true;
                $permissions['governance.audit.view'] = true;
            }
        } elseif ($isHead) {
            $permissions['governance.view'] = true;
            $permissions['governance.findings.manage'] = true;
        }

        if ($isHead) {
            $permissions['decisions.view'] = true;
            $permissions['decisions.manage'] = true;
            $permissions['kpi.catalog.manage'] = true;
            $permissions['actions.manage'] = true;
            $permissions['kpi.custom.manage'] = true;
            $permissions['data.import.manage'] = true;
            $permissions['integrations.view'] = true;
            $permissions['integrations.manage'] = true;
            $permissions['reports.export'] = true;
        }

        return $permissions;
    }

    public function effectivePermissions(): array
    {
        $permissions = $this->defaultPermissions();
        $overrides = $this->relationLoaded('permissionOverrides')
            ? $this->permissionOverrides
            : $this->permissionOverrides()->get();

        foreach ($overrides as $override) {
            if (in_array($override->permission, self::PERMISSIONS, true)) {
                $permissions[$override->permission] = (bool) $override->allowed;
            }
        }

        return collect(self::PERMISSIONS)
            ->mapWithKeys(fn (string $permission): array => [$permission => (bool) ($permissions[$permission] ?? false)])
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        if (! in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }

        $override = $this->relationLoaded('permissionOverrides')
            ? $this->permissionOverrides->firstWhere('permission', $permission)
            : $this->permissionOverrides()->where('permission', $permission)->first();

        if ($override) {
            return (bool) $override->allowed;
        }

        return (bool) ($this->defaultPermissions()[$permission] ?? false);
    }

    public function canAccess(string $module): bool
    {
        return match ($module) {
            'overview' => $this->hasPermission('overview.view'),
            'certification' => $this->hasPermission('certification.view'),
            'finance' => $this->hasPermission('finance.view'),
            'it' => $this->hasPermission('it.view'),
            'governance' => $this->hasPermission('governance.view'),
            'admin' => $this->hasPermission('users.manage'),
            'integrations' => $this->hasPermission('integrations.view'),
            'decisions' => $this->hasPermission('decisions.view'),
            'reports' => $this->hasPermission('reports.view'),
            default => false,
        };
    }

    public function canManageMasterData(string $type): bool
    {
        return match ($type) {
            'certification-schemes', 'tuks', 'assessors' => $this->hasPermission('master_data.certification.manage'),
            'it-services' => $this->hasPermission('master_data.it.manage'),
            default => false,
        };
    }
}
