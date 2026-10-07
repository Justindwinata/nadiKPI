<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\AuthenticationEvent;
use App\Models\Department;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'users' => User::query()
                ->with(['department:id,code,name', 'permissionOverrides:id,user_id,permission,allowed,reason,granted_by'])
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => $this->serializeUser($user)),
            'departments' => Department::query()->select(['id', 'code', 'name'])->orderBy('name')->get(),
            'roles' => [
                ['value' => 'director', 'label' => 'Pimpinan / Administrator'],
                ['value' => 'department_head', 'label' => 'Kepala Divisi'],
                ['value' => 'analyst', 'label' => 'Staf Operasional / Analyst'],
                ['value' => 'viewer', 'label' => 'Viewer'],
            ],
            'permission_catalog' => collect(User::PERMISSIONS)->map(fn (string $permission) => [
                'permission' => $permission,
                'label' => $this->permissionLabel($permission),
                'sensitive' => in_array($permission, ['users.manage', 'finance.reverse', 'governance.provenance.manage', 'integrations.manage', 'kpi.catalog.manage', 'decisions.review', 'reports.export'], true),
            ])->values(),
            'authentication_events' => AuthenticationEvent::query()
                ->with('user:id,name')
                ->latest('occurred_at')
                ->limit(80)
                ->get()
                ->map(fn (AuthenticationEvent $event) => [
                    'id' => $event->id,
                    'user' => $event->user?->name,
                    'email' => $event->email,
                    'event' => $event->event,
                    'successful' => $event->successful,
                    'ip_address' => $event->ip_address,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ]),
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'role' => ['required', Rule::in(['director', 'department_head', 'analyst', 'viewer'])],
            'position' => ['required', 'string', 'max:160'],
            'temporary_password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        $user = DB::transaction(function () use ($request, $data): User {
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentAdminActor($actor);
            if ($data['role'] === 'director' && $actor->role !== 'director') {
                abort(403, 'Hanya Pimpinan yang dapat membuat akun Pimpinan/Administrator.');
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'department_id' => $data['department_id'],
                'role' => $data['role'],
                'position' => $data['position'],
                'password' => $data['temporary_password'],
                'is_active' => true,
                'must_change_password' => true,
                'password_changed_at' => null,
            ]);
            $this->audit($request, 'create_user', $user, [
                'name' => $user->name,
                'email' => $user->email,
                'department_id' => $user->department_id,
                'role' => $user->role,
                'position' => $user->position,
                'must_change_password' => true,
            ]);

            return $user;
        });

        return response()->json(['user' => $this->serializeUser($user->load(['department', 'permissionOverrides']))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'role' => ['required', Rule::in(['director', 'department_head', 'analyst', 'viewer'])],
            'position' => ['required', 'string', 'max:160'],
        ]);

        $user = DB::transaction(function () use ($request, $user, $data): User {
            [$actor, $locked] = $this->lockAdminActorAndTarget($request, $user);
            if ($actor->is($locked) && ($data['role'] !== $locked->role || (int) $data['department_id'] !== (int) $locked->department_id)) {
                throw ValidationException::withMessages(['role' => 'Role atau divisi akun sendiri tidak dapat diubah dari sesi aktif.']);
            }
            if (($locked->role === 'director' || $data['role'] === 'director') && $actor->role !== 'director') {
                abort(403, 'Hanya Pimpinan yang dapat mengubah role Pimpinan/Administrator.');
            }
            if ($locked->role === 'director' && $data['role'] !== 'director') {
                $this->ensureAnotherActiveDirector($locked);
            }

            $before = $locked->only(['name', 'email', 'department_id', 'role', 'position']);
            $locked->update([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'department_id' => $data['department_id'],
                'role' => $data['role'],
                'position' => $data['position'],
            ]);
            if ($data['role'] !== 'director') {
                $locked->permissionOverrides()->where('permission', 'users.manage')->delete();
            }
            $this->audit($request, 'update_user', $locked, ['before' => $before, 'after' => $locked->fresh()->only(array_keys($before))]);

            return $locked->fresh(['department', 'permissionOverrides']);
        });

        return response()->json(['user' => $this->serializeUser($user)]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $user = DB::transaction(function () use ($request, $user, $data): User {
            [$actor, $locked] = $this->lockAdminActorAndTarget($request, $user);
            if ($actor->is($locked) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan.']);
            }
            if ($locked->role === 'director' && ! $data['is_active']) {
                $this->ensureAnotherActiveDirector($locked);
            }

            $before = $locked->only(['is_active', 'deactivated_at', 'deactivated_by']);
            $locked->forceFill([
                'is_active' => (bool) $data['is_active'],
                'deactivated_at' => $data['is_active'] ? null : now(),
                'deactivated_by' => $data['is_active'] ? null : $actor->id,
            ])->save();

            if (! $data['is_active']) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }

            $this->audit($request, $data['is_active'] ? 'activate_user' : 'deactivate_user', $locked, [
                'before' => $before,
                'after' => $locked->fresh()->only(array_keys($before)),
                'reason' => $data['reason'],
            ]);

            return $locked->fresh(['department', 'permissionOverrides']);
        });

        return response()->json(['user' => $this->serializeUser($user)]);
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'overrides' => ['present', 'array', 'max:40'],
            'overrides.*.permission' => ['required', 'string', Rule::in(User::PERMISSIONS)],
            'overrides.*.allowed' => ['required', 'boolean'],
            'overrides.*.reason' => ['required', 'string', 'max:500'],
        ]);

        $permissions = collect($data['overrides'])->pluck('permission');
        if ($permissions->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['overrides' => 'Satu permission hanya boleh memiliki satu override.']);
        }

        $user = DB::transaction(function () use ($request, $user, $data, $permissions): User {
            [$actor, $locked] = $this->lockAdminActorAndTarget($request, $user);
            abort_unless($actor->role === 'director', 403, 'Hanya Pimpinan yang dapat mengubah permission override.');
            if ($actor->is($locked)) {
                throw ValidationException::withMessages(['overrides' => 'Permission akun sendiri tidak dapat diubah dari sesi aktif.']);
            }
            if ($permissions->contains('users.manage') && $locked->role !== 'director') {
                throw ValidationException::withMessages(['overrides' => 'Permission users.manage tidak dapat diberikan kepada akun non-Pimpinan.']);
            }

            $before = $locked->permissionOverrides()->lockForUpdate()->get(['permission', 'allowed', 'reason'])->toArray();
            $locked->permissionOverrides()->delete();
            foreach ($data['overrides'] as $override) {
                UserPermission::create([
                    'user_id' => $locked->id,
                    'permission' => $override['permission'],
                    'allowed' => $override['allowed'],
                    'reason' => $override['reason'],
                    'granted_by' => $actor->id,
                ]);
            }
            $this->audit($request, 'update_permissions', $locked, [
                'before' => $before,
                'after' => $data['overrides'],
            ]);

            return $locked->fresh(['department', 'permissionOverrides']);
        });

        return response()->json(['user' => $this->serializeUser($user)]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'temporary_password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $user, $data): void {
            [$actor, $locked] = $this->lockAdminActorAndTarget($request, $user);
            if ($actor->is($locked)) {
                throw ValidationException::withMessages(['temporary_password' => 'Gunakan menu Ganti Kata Sandi untuk akun sendiri.']);
            }
            $locked->forceFill([
                'password' => $data['temporary_password'],
                'must_change_password' => true,
                'password_changed_at' => null,
                'remember_token' => Str::random(60),
            ])->save();
            DB::table('sessions')->where('user_id', $locked->id)->delete();

            $this->audit($request, 'admin_password_reset', $locked, [
                'must_change_password' => true,
                'reason' => $data['reason'],
            ]);
        });

        return response()->json(['message' => 'Kata sandi sementara diterapkan. Semua sesi pengguna telah diakhiri.']);
    }

    /**
     * Lock the authenticated administrator and target user in deterministic id order.
     * This closes TOCTOU windows where the actor is deactivated, demoted, or loses
     * users.manage while a sensitive administration request is already in flight.
     *
     * @return array{0: User, 1: User}
     */
    private function lockAdminActorAndTarget(Request $request, User $target): array
    {
        $actorId = (int) $request->user()->id;
        $targetId = (int) $target->id;
        $lockedUsers = User::query()
            ->whereIn('id', array_values(array_unique([$actorId, $targetId])))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var User $actor */
        $actor = $lockedUsers->get($actorId) ?? abort(403, 'Akun administrator tidak lagi tersedia.');
        /** @var User $lockedTarget */
        $lockedTarget = $lockedUsers->get($targetId) ?? abort(404);
        $this->assertCurrentAdminActor($actor);

        return [$actor, $lockedTarget];
    }

    private function assertCurrentAdminActor(User $actor): void
    {
        abort_unless($actor->is_active && $actor->hasPermission('users.manage'), 403, 'Hak administrasi akun sudah berubah. Muat ulang sesi dan coba lagi.');
    }

    private function serializeUser(User $user): array
    {
        $user->loadMissing(['department', 'permissionOverrides']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'department_id' => $user->department_id,
            'department' => $user->department,
            'role' => $user->role,
            'position' => $user->position,
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'last_login_ip' => $user->last_login_ip,
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'permission_overrides' => $user->permissionOverrides->map(fn (UserPermission $override) => [
                'permission' => $override->permission,
                'allowed' => $override->allowed,
                'reason' => $override->reason,
            ])->values(),
            'effective_permissions' => $user->effectivePermissions(),
        ];
    }

    private function ensureAnotherActiveDirector(User $target): void
    {
        $otherDirectors = User::query()
            ->where('role', 'director')
            ->where('is_active', true)
            ->lockForUpdate()
            ->get(['id'])
            ->where('id', '!=', $target->id)
            ->count();

        if ($otherDirectors < 1) {
            throw ValidationException::withMessages(['role' => 'Minimal satu akun Pimpinan aktif harus tetap tersedia.']);
        }
    }

    private function audit(Request $request, string $action, User $entity, array $changes): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => User::class,
            'entity_id' => $entity->id,
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }

    private function permissionLabel(string $permission): string
    {
        return str($permission)
            ->replace('.', ' · ')
            ->replace('_', ' ')
            ->title()
            ->toString();
    }
}
