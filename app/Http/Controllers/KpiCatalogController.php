<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\KpiConfiguration;
use App\Models\KpiDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KpiCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = KpiDefinition::query()
            ->with(['department:id,code,name', 'configurations.creator:id,name'])
            ->whereNull('archived_at')
            ->orderBy('department_id')
            ->orderBy('code');

        if ($user->role !== 'director') {
            $query->where('department_id', $user->department_id);
        }

        $definitions = $query->get()->map(function (KpiDefinition $kpi) {
            $current = $kpi->configurationFor(now());

            return [
                'id' => $kpi->id,
                'department_id' => $kpi->department_id,
                'department' => $kpi->department,
                'code' => $kpi->code,
                'name' => $kpi->name,
                'description' => $kpi->description,
                'unit' => $kpi->unit,
                'direction' => $kpi->direction,
                'cadence' => $kpi->cadence,
                'data_source' => $kpi->data_source,
                'calculation_mode' => $kpi->calculation_mode,
                'is_system_derived' => $kpi->isSystemDerived(),
                'current_configuration' => $current,
                'configurations' => $kpi->configurations->sortByDesc('effective_from')->values(),
            ];
        });

        return response()->json([
            'definitions' => $definitions,
            'departments' => Department::query()
                ->whereNot('code', 'leadership')
                ->when($user->role !== 'director', fn ($q) => $q->whereKey($user->department_id))
                ->select(['id', 'code', 'name'])
                ->orderBy('name')
                ->get(),
            'can_manage' => $user->hasPermission('kpi.catalog.manage'),
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/', Rule::unique('kpi_definitions', 'code')],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', 'string', 'max:30'],
            'direction' => ['required', Rule::in(['higher', 'lower'])],
            'cadence' => ['required', Rule::in(['monthly', 'quarterly', 'annual'])],
            'data_source' => ['required', 'string', 'max:255'],
            'target' => ['required', 'numeric'],
            'warning_threshold' => ['nullable', 'numeric'],
            'weight' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'owner_name' => ['required', 'string', 'max:160'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'change_reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->authorizeDepartment($request, (int) $data['department_id']);
        $this->validateThreshold($data['direction'], (float) $data['target'], isset($data['warning_threshold']) ? (float) $data['warning_threshold'] : null);

        $kpi = DB::transaction(function () use ($data, $request) {
            $kpi = KpiDefinition::query()->create([
                'department_id' => $data['department_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'unit' => $data['unit'],
                'direction' => $data['direction'],
                'weight' => $data['weight'],
                'target' => $data['target'],
                'warning_threshold' => $data['warning_threshold'] ?? null,
                'cadence' => $data['cadence'],
                'data_source' => $data['data_source'],
                'calculation_mode' => 'manual',
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);

            $config = $kpi->configurations()->create([
                'target' => $data['target'],
                'warning_threshold' => $data['warning_threshold'] ?? null,
                'weight' => $data['weight'],
                'owner_name' => $data['owner_name'],
                'effective_from' => $data['effective_from'],
                'is_active' => true,
                'change_reason' => $data['change_reason'],
                'created_by' => $request->user()->id,
            ]);

            $this->audit($request, 'create_kpi_definition', $kpi, ['after' => $kpi->toArray(), 'configuration' => $config->toArray()]);

            return $kpi;
        });

        return response()->json(['kpi' => $kpi->load('configurations')], 201);
    }

    public function update(Request $request, KpiDefinition $kpi): JsonResponse
    {
        $this->authorizeKpi($request, $kpi);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', 'string', 'max:30'],
            'cadence' => ['required', Rule::in(['monthly', 'quarterly', 'annual'])],
            'data_source' => ['required', 'string', 'max:255'],
            'change_reason' => ['required', 'string', 'max:1000'],
        ]);

        $kpi = DB::transaction(function () use ($request, $kpi, $data): KpiDefinition {
            $locked = KpiDefinition::query()->whereKey($kpi->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(['name', 'description', 'unit', 'cadence', 'data_source']);
            $locked->update(collect($data)->except('change_reason')->all());
            $this->audit($request, 'update_kpi_definition', $locked, ['before' => $before, 'after' => $locked->fresh()->only(array_keys($before)), 'reason' => $data['change_reason']]);
            return $locked->fresh();
        });

        return response()->json(['kpi' => $kpi]);
    }

    public function configure(Request $request, KpiDefinition $kpi): JsonResponse
    {
        $this->authorizeKpi($request, $kpi);
        $data = $request->validate([
            'target' => ['required', 'numeric'],
            'warning_threshold' => ['nullable', 'numeric'],
            'weight' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'owner_name' => ['required', 'string', 'max:160'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
            'change_reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->validateThreshold($kpi->direction, (float) $data['target'], isset($data['warning_threshold']) ? (float) $data['warning_threshold'] : null);
        $from = CarbonImmutable::parse($data['effective_from'])->startOfDay();
        $until = isset($data['effective_until']) ? CarbonImmutable::parse($data['effective_until'])->startOfDay() : null;

        $configuration = DB::transaction(function () use ($request, $kpi, $data, $from, $until) {
            $lockedKpi = KpiDefinition::query()->whereKey($kpi->id)->lockForUpdate()->firstOrFail();
            $previous = $lockedKpi->configurations()->orderByDesc('effective_from')->first();
            if ($previous && $from->lte($previous->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'Versi konfigurasi baru harus dimulai setelah versi terakhir. Histori tidak boleh ditimpa.']);
            }

            if ($previous && (! $previous->effective_until || $from->lte($previous->effective_until))) {
                $previous->update(['effective_until' => $from->subDay()->toDateString()]);
            }

            $futureConflict = $lockedKpi->configurations()
                ->where('effective_from', '>=', $from->toDateString())
                ->exists();
            if ($futureConflict) {
                throw ValidationException::withMessages(['effective_from' => 'Periode konfigurasi bertabrakan dengan versi lain.']);
            }

            $configuration = $lockedKpi->configurations()->create([
                'target' => $data['target'],
                'warning_threshold' => $data['warning_threshold'] ?? null,
                'weight' => $data['weight'],
                'owner_name' => $data['owner_name'],
                'effective_from' => $from->toDateString(),
                'effective_until' => $until?->toDateString(),
                'is_active' => $data['is_active'],
                'change_reason' => $data['change_reason'],
                'created_by' => $request->user()->id,
            ]);

            if ($from->lte(now()) && (! $until || $until->gte(now()))) {
                $lockedKpi->update([
                    'target' => $data['target'],
                    'warning_threshold' => $data['warning_threshold'] ?? null,
                    'weight' => $data['weight'],
                    'is_active' => $data['is_active'],
                ]);
            }

            $this->audit($request, 'version_kpi_configuration', $configuration, ['after' => $configuration->toArray()]);

            return $configuration;
        });

        return response()->json(['configuration' => $configuration], 201);
    }

    private function validateThreshold(string $direction, float $target, ?float $warning): void
    {
        if ($warning === null) {
            return;
        }

        if ($direction === 'higher' && $warning > $target) {
            throw ValidationException::withMessages(['warning_threshold' => 'Untuk KPI higher-is-better, batas waspada harus lebih kecil atau sama dengan target.']);
        }
        if ($direction === 'lower' && $warning < $target) {
            throw ValidationException::withMessages(['warning_threshold' => 'Untuk KPI lower-is-better, batas waspada harus lebih besar atau sama dengan target.']);
        }
    }

    private function authorizeDepartment(Request $request, int $departmentId): void
    {
        abort_unless($request->user()->hasPermission('kpi.catalog.manage'), 403);
        if ($request->user()->role !== 'director') {
            abort_unless((int) $request->user()->department_id === $departmentId, 403);
        }
    }

    private function authorizeKpi(Request $request, KpiDefinition $kpi): void
    {
        $this->authorizeDepartment($request, (int) $kpi->department_id);
    }

    private function audit(Request $request, string $action, $entity, array $changes): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
