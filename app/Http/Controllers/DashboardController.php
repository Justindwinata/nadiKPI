<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportingPeriodRequest;
use App\Models\ActionItem;
use App\Models\Department;
use App\Models\KpiDefinition;
use App\Services\KpiAnalyticsService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(ReportingPeriodRequest $request, KpiAnalyticsService $analytics): JsonResponse
    {
        $department = $request->user()->role === 'director' ? null : $request->user()->department?->code;
        $departmentId = $request->user()->role === 'director' ? null : $request->user()->department_id;

        return response()->json([
            'overview' => $analytics->overview($department, $request->period()),
            'actions' => ActionItem::query()
                ->with(['department', 'kpi', 'riskSignal'])
                ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
                ->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'blocked' THEN 3 ELSE 4 END")
                ->orderBy('due_date')
                ->get(),
            'action_options' => [
                'departments' => Department::query()
                    ->select(['id', 'code', 'name'])
                    ->whereNot('code', 'leadership')
                    ->when($departmentId, fn ($query) => $query->whereKey($departmentId))
                    ->orderBy('name')
                    ->get(),
                'kpis' => KpiDefinition::query()
                    ->with('configurations')
                    ->select(['id', 'department_id', 'code', 'name', 'is_active'])
                    ->whereNull('archived_at')
                    ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
                    ->orderBy('code')
                    ->get()
                    ->filter(function (KpiDefinition $kpi) {
                        $config = $kpi->configurationFor(now());
                        return $kpi->configurations->isEmpty() ? (bool) $kpi->is_active : (bool) ($config?->is_active ?? false);
                    })
                    ->values()
                    ->map(fn (KpiDefinition $kpi) => $kpi->only(['id', 'department_id', 'code', 'name'])),
            ],
        ]);
    }
}
