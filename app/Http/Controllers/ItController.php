<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportingPeriodRequest;
use App\Http\Requests\ResolveItIncidentRequest;
use App\Http\Requests\StoreDataQualityRunRequest;
use App\Http\Requests\StoreItIncidentRequest;
use App\Models\AuditLog;
use App\Models\DataQualityRun;
use App\Models\ItIncident;
use App\Services\KpiAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItController extends Controller
{
    public function index(ReportingPeriodRequest $request, KpiAnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->it($request->period()));
    }

    public function storeIncident(StoreItIncidentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $incident = DB::transaction(function () use ($data, $request): ItIncident {
            $incident = ItIncident::create($data + ['status' => 'open']);
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'create',
                'entity_type' => ItIncident::class,
                'entity_id' => $incident->id,
                'changes' => $data,
                'ip_address' => $request->ip(),
            ]);

            return $incident;
        });

        return response()->json(['incident' => $incident->load('service')], 201);
    }

    public function investigate(Request $request, ItIncident $incident): JsonResponse
    {
        abort_unless($request->user()?->is_active && $request->user()->canAccess('it'), 403);

        $incident = DB::transaction(function () use ($incident, $request): ItIncident {
            $locked = ItIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'resolved') {
                throw ValidationException::withMessages(['status' => 'Insiden yang sudah selesai tidak dapat dikembalikan ke investigasi.']);
            }

            $changes = [
                'status' => 'investigating',
                'acknowledged_at' => $locked->acknowledged_at ?? now(),
            ];
            $locked->update($changes);
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'investigate',
                'entity_type' => ItIncident::class,
                'entity_id' => $locked->id,
                'changes' => $changes,
                'ip_address' => $request->ip(),
            ]);

            return $locked->fresh();
        });

        return response()->json(['incident' => $incident->load('service')]);
    }

    public function resolve(ResolveItIncidentRequest $request, ItIncident $incident): JsonResponse
    {
        $data = $request->validated();

        $incident = DB::transaction(function () use ($incident, $request, $data): ItIncident {
            $locked = ItIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'resolved') {
                throw ValidationException::withMessages(['status' => 'Insiden ini sudah selesai.']);
            }
            if ($locked->status !== 'investigating') {
                throw ValidationException::withMessages(['status' => 'Mulai tahap investigasi sebelum menutup insiden.']);
            }

            $resolvedAt = CarbonImmutable::parse($data['resolved_at'] ?? now());
            if ($resolvedAt->lt($locked->started_at)) {
                throw ValidationException::withMessages(['resolved_at' => 'Waktu selesai tidak boleh lebih awal dari waktu mulai insiden.']);
            }

            $changes = [
                'status' => 'resolved',
                'acknowledged_at' => $locked->acknowledged_at ?? now(),
                'resolved_at' => $resolvedAt,
                'resolved_by' => $request->user()->id,
                'resolution_summary' => $data['resolution_summary'],
                'root_cause' => $data['root_cause'] ?? null,
            ];
            $locked->update($changes);
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'resolve',
                'entity_type' => ItIncident::class,
                'entity_id' => $locked->id,
                'changes' => $changes,
                'ip_address' => $request->ip(),
            ]);

            return $locked->fresh();
        });

        return response()->json(['incident' => $incident->load('service')]);
    }

    public function storeDataQualityRun(StoreDataQualityRunRequest $request): JsonResponse
    {
        $data = $request->validated();
        $run = DB::transaction(function () use ($data, $request): DataQualityRun {
            $run = DataQualityRun::create($data + [
                'missing_required_records' => $data['missing_required_records'] ?? 0,
                'duplicate_records' => $data['duplicate_records'] ?? 0,
                'freshness_failures' => $data['freshness_failures'] ?? 0,
                'recorded_by' => $request->user()->id,
            ]);
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'create',
                'entity_type' => DataQualityRun::class,
                'entity_id' => $run->id,
                'changes' => $data,
                'ip_address' => $request->ip(),
            ]);

            return $run;
        });

        return response()->json([
            'run' => $run,
            'quality_score' => $run->qualityScore(),
        ], 201);
    }
}
