<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMasterDataRequest;
use App\Http\Requests\UpdateMasterDataRequest;
use App\Models\Assessor;
use App\Models\AuditLog;
use App\Models\CertificationScheme;
use App\Models\ItService;
use App\Models\Tuk;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterDataController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $types = $this->accessibleTypes($request->user());
        abort_if($types === [], 403);

        $entityClasses = collect($types)->map(fn (string $type): string => $this->modelClass($type));

        return response()->json([
            'catalogs' => collect($types)->mapWithKeys(fn (string $type): array => [
                $type => $this->recordsFor($type),
            ]),
            'available_types' => $types,
            'activity' => AuditLog::query()
                ->with('user:id,name')
                ->whereIn('entity_type', $entityClasses)
                ->latest()
                ->limit(12)
                ->get()
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'type' => array_search($log->entity_type, $this->modelMap(), true),
                    'label' => $this->activityLabel($log),
                    'user' => $log->user?->name ?? 'Sistem',
                    'created_at' => $log->created_at?->toIso8601String(),
                ]),
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    public function store(StoreMasterDataRequest $request, string $type): JsonResponse
    {
        $data = $request->validated();
        if ($type === 'it-services' && empty($data['monitoring_started_at'])) {
            $data['monitoring_started_at'] = now();
        }
        $modelClass = $this->modelClass($type);

        $record = DB::transaction(function () use ($data, $modelClass, $request, $type): Model {
            $actor = $this->lockAuthorizedActor($request, $type);
            $record = $modelClass::query()->create($data);
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'create',
                'entity_type' => $modelClass,
                'entity_id' => $record->getKey(),
                'changes' => $data,
                'ip_address' => $request->ip(),
            ]);

            return $record;
        });

        return response()->json([
            'record' => $record,
            'type' => $type,
            'synced_at' => now()->toIso8601String(),
        ], 201);
    }

    public function update(UpdateMasterDataRequest $request, string $type, int $id): JsonResponse
    {
        $modelClass = $this->modelClass($type);
        $data = $request->validated();
        $reason = $data['change_reason'];
        unset($data['change_reason']);

        $record = DB::transaction(function () use ($id, $data, $reason, $request, $modelClass, $type): Model {
            $actor = $this->lockAuthorizedActor($request, $type);
            $record = $modelClass::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($record->archived_at, 422, 'Data yang sudah diarsipkan harus diaktifkan kembali sebelum diedit.');
            $before = $record->toArray();
            $record->update($data);
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'update_master_data',
                'entity_type' => $modelClass,
                'entity_id' => $record->getKey(),
                'changes' => ['before' => $before, 'after' => $record->fresh()->toArray(), 'reason' => $reason],
                'ip_address' => $request->ip(),
            ]);

            return $record->fresh();
        });

        return response()->json(['record' => $record, 'type' => $type, 'synced_at' => now()->toIso8601String()]);
    }

    public function archive(Request $request, string $type, int $id): JsonResponse
    {
        abort_unless($request->user()->canManageMasterData($type), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $modelClass = $this->modelClass($type);
        $record = DB::transaction(function () use ($id, $type, $data, $request, $modelClass): Model {
            $actor = $this->lockAuthorizedActor($request, $type);
            $record = $modelClass::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($record->archived_at) {
                throw ValidationException::withMessages(['record' => 'Data sudah diarsipkan.']);
            }

            $this->assertCanArchive($type, $record);
            $before = $record->toArray();
            $lifecycle = match ($type) {
                'certification-schemes' => ['is_active' => false],
                'tuks', 'assessors' => ['status' => 'inactive'],
                'it-services' => ['status' => 'maintenance'],
            };
            $record->forceFill($lifecycle + [
                'archived_at' => now(),
                'archived_by' => $actor->id,
                'archive_reason' => $data['reason'],
            ])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'archive_master_data',
                'entity_type' => $modelClass,
                'entity_id' => $record->getKey(),
                'changes' => ['before' => $before, 'after' => $record->fresh()->toArray(), 'reason' => $data['reason']],
                'ip_address' => $request->ip(),
            ]);

            return $record->fresh();
        });

        return response()->json(['record' => $record, 'type' => $type]);
    }

    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        abort_unless($request->user()->canManageMasterData($type), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $modelClass = $this->modelClass($type);
        $record = DB::transaction(function () use ($id, $type, $data, $request, $modelClass): Model {
            $actor = $this->lockAuthorizedActor($request, $type);
            $record = $modelClass::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $record->archived_at) {
                throw ValidationException::withMessages(['record' => 'Data belum diarsipkan.']);
            }

            $before = $record->toArray();
            $lifecycle = match ($type) {
                'certification-schemes' => ['is_active' => true],
                'tuks', 'assessors' => ['status' => 'active'],
                'it-services' => ['status' => 'operational'],
            };
            $record->forceFill($lifecycle + ['archived_at' => null, 'archived_by' => null, 'archive_reason' => null])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'restore_master_data',
                'entity_type' => $modelClass,
                'entity_id' => $record->getKey(),
                'changes' => ['before' => $before, 'after' => $record->fresh()->toArray(), 'reason' => $data['reason']],
                'ip_address' => $request->ip(),
            ]);

            return $record->fresh();
        });

        return response()->json(['record' => $record, 'type' => $type]);
    }

    private function lockAuthorizedActor(Request $request, string $type): User
    {
        $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->is_active && $actor->canManageMasterData($type), 403);

        return $actor;
    }

    /**
     * @return list<string>
     */
    private function accessibleTypes(User $user): array
    {
        return collect(array_keys($this->modelMap()))
            ->filter(fn (string $type): bool => $user->canManageMasterData($type))
            ->values()
            ->all();
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function modelMap(): array
    {
        return [
            'certification-schemes' => CertificationScheme::class,
            'tuks' => Tuk::class,
            'assessors' => Assessor::class,
            'it-services' => ItService::class,
        ];
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(string $type): string
    {
        return $this->modelMap()[$type];
    }

    private function recordsFor(string $type): mixed
    {
        return match ($type) {
            'certification-schemes' => CertificationScheme::query()
                ->select(['id', 'code', 'name', 'category', 'units_count', 'is_active', 'valid_until', 'evidence_reference', 'archived_at', 'archive_reason', 'updated_at'])
                ->orderBy('name')
                ->get(),
            'tuks' => Tuk::query()
                ->select(['id', 'code', 'name', 'city', 'status', 'monthly_capacity', 'verification_valid_until', 'evidence_reference', 'archived_at', 'archive_reason', 'updated_at'])
                ->orderBy('name')
                ->get(),
            'assessors' => Assessor::query()
                ->select(['id', 'registration_no', 'name', 'specialization', 'status', 'valid_until', 'archived_at', 'archive_reason', 'updated_at'])
                ->orderBy('name')
                ->get(),
            'it-services' => ItService::query()
                ->select(['id', 'name', 'owner', 'target_uptime', 'monitoring_started_at', 'status', 'archived_at', 'archive_reason', 'updated_at'])
                ->orderBy('name')
                ->get(),
        };
    }

    private function assertCanArchive(string $type, Model $record): void
    {
        $blocking = match ($type) {
            'certification-schemes', 'tuks', 'assessors' => $record->batches()->whereNot('status', 'completed')->count(),
            'it-services' => $record->incidents()->whereNull('resolved_at')->count(),
            default => 0,
        };

        if ($blocking > 0) {
            throw ValidationException::withMessages([
                'record' => "Data masih dipakai oleh {$blocking} proses aktif. Selesaikan atau pindahkan dependensi sebelum arsip.",
            ]);
        }
    }

    private function activityLabel(AuditLog $log): string
    {
        $changes = $log->changes ?? [];
        $values = is_array($changes['after'] ?? null) ? $changes['after'] : $changes;

        return (string) ($values['name'] ?? $values['code'] ?? $values['registration_no'] ?? 'Data referensi');
    }
}
