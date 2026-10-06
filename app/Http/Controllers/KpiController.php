<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreKpiMeasurementRequest;
use App\Models\AuditLog;
use App\Models\KpiDefinition;
use App\Models\KpiMeasurement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KpiController extends Controller
{
    public function storeMeasurement(StoreKpiMeasurementRequest $request, KpiDefinition $kpi): JsonResponse
    {
        $data = $request->validated();
        [$measurement, $created] = DB::transaction(function () use ($data, $kpi, $request): array {
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->hasPermission('kpi.custom.manage'), 403);

            $lockedKpi = KpiDefinition::query()
                ->with('configurations')
                ->whereKey($kpi->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Route authorization is evaluated before the lock; re-check the business scope against
            // the locked row so a stale route-bound model can never authorize a changed KPI.
            abort_unless(
                $actor->role === 'director' || $actor->department_id === $lockedKpi->department_id,
                403
            );

            if ($lockedKpi->archived_at || $lockedKpi->isSystemDerived()) {
                throw ValidationException::withMessages([
                    'actual' => $lockedKpi->archived_at
                        ? 'KPI yang sudah diarsipkan tidak menerima pengukuran baru.'
                        : 'KPI ini dihitung otomatis dari transaksi operasional dan tidak dapat diubah manual.',
                ]);
            }

            $config = $lockedKpi->configurationFor($data['period']);
            if (($lockedKpi->configurations->isNotEmpty() && ! $config) || ($config && ! $config->is_active)) {
                throw ValidationException::withMessages(['period' => 'KPI tidak aktif atau belum memiliki konfigurasi yang berlaku pada periode pengukuran tersebut.']);
            }
            if ($lockedKpi->configurations->isEmpty() && ! $lockedKpi->is_active) {
                throw ValidationException::withMessages(['period' => 'KPI tidak aktif pada periode pengukuran tersebut.']);
            }

            $targetSnapshot = (float) ($config?->target ?? $lockedKpi->target);
            $measurement = KpiMeasurement::query()
                ->where('kpi_definition_id', $lockedKpi->id)
                ->whereDate('period', $data['period'])
                ->lockForUpdate()
                ->first();
            $before = $measurement?->toArray();

            $created = ! $measurement;
            if ($measurement) {
                $measurement->update([
                    'actual' => $data['actual'],
                    'target_snapshot' => $targetSnapshot,
                    'notes' => $data['notes'] ?? null,
                    'recorded_by' => $actor->id,
                ]);
            } else {
                $measurement = KpiMeasurement::create([
                    'kpi_definition_id' => $lockedKpi->id,
                    'period' => $data['period'],
                    'actual' => $data['actual'],
                    'target_snapshot' => $targetSnapshot,
                    'notes' => $data['notes'] ?? null,
                    'recorded_by' => $actor->id,
                ]);
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => $before ? 'update_measurement' : 'create_measurement',
                'entity_type' => KpiMeasurement::class,
                'entity_id' => $measurement->id,
                'changes' => ['before' => $before, 'after' => $measurement->fresh()->toArray()],
                'ip_address' => $request->ip(),
            ]);

            return [$measurement->fresh(), $created];
        });

        return response()->json(['measurement' => $measurement], $created ? 201 : 200);
    }
}
