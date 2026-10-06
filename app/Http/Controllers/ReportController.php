<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportingPeriodRequest;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\ManagementReview;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\ReportExportService;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function index(Request $request, ReportingService $reports): JsonResponse
    {
        $user = $request->user();
        $history = $reports->historyFor($user)->map(fn (ReportSnapshot $snapshot) => $this->summary($snapshot));

        return response()->json([
            'catalog' => $reports->catalogFor($user),
            'departments' => $user->role === 'director'
                ? Department::query()->where('code', '!=', 'leadership')->orderBy('name')->get(['id', 'code', 'name'])
                : collect([$user->department])->filter()->map(fn ($department) => $department->only(['id', 'code', 'name']))->values(),
            'management_reviews' => $user->hasPermission('decisions.review')
                ? ManagementReview::query()->orderByDesc('period_end')->limit(40)->get(['id', 'reference', 'title', 'status', 'period_start', 'period_end'])
                : collect(),
            'history' => $history,
            'permissions' => ['export' => $user->hasPermission('reports.export')],
        ]);
    }

    public function store(ReportingPeriodRequest $request, ReportingService $reports): JsonResponse
    {
        $data = $request->validate([
            'report_type' => ['required', Rule::in(['executive', 'department', 'certification', 'finance', 'it', 'governance', 'risk_action', 'management_review'])],
            'department_code' => ['nullable', 'string', 'max:60'],
            'management_review_id' => ['nullable', 'integer', 'exists:management_reviews,id'],
        ]);

        $snapshot = DB::transaction(function () use ($request, $reports, $data): ReportSnapshot {
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->hasPermission('reports.view'), 403);

            $snapshot = $reports->generate($actor, $data['report_type'], $request->period(), $data);
            $this->audit($request, 'generate_report_snapshot', $snapshot, [
                'reference' => $snapshot->reference,
                'report_type' => $snapshot->report_type,
                'period_start' => $snapshot->period_start?->toDateString(),
                'period_end' => $snapshot->period_end?->toDateString(),
                'content_hash' => $snapshot->content_hash,
            ]);

            return $snapshot;
        });

        return response()->json(['report' => $this->detail($snapshot->fresh(['generator:id,name', 'department:id,code,name']))], 201);
    }

    public function show(Request $request, ReportSnapshot $report, ReportingService $reports): JsonResponse
    {
        $this->authorizeSnapshot($request, $report, $reports);
        return response()->json(['report' => $this->detail($report->load(['generator:id,name', 'department:id,code,name']))]);
    }

    public function export(Request $request, ReportSnapshot $report, string $format, ReportingService $reports, ReportExportService $exporter): Response
    {
        $this->authorizeSnapshot($request, $report, $reports);
        if (! $request->user()->hasPermission('reports.export')) abort(403);
        if (! in_array($format, ['json', 'csv', 'zip'], true)) abort(404);

        [$body, $contentType, $extension] = match ($format) {
            'json' => [$exporter->json($report), 'application/json; charset=UTF-8', 'json'],
            'csv' => [$exporter->csv($report), 'text/csv; charset=UTF-8', 'csv'],
            'zip' => [$exporter->evidencePack($report), 'application/zip', 'zip'],
        };
        $this->audit($request, 'export_report_snapshot', $report, ['format' => $format, 'content_hash' => $report->content_hash]);
        $filename = strtolower($report->reference).'.'.$extension;

        return response($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-NADI-Report-Hash' => $report->content_hash,
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function print(Request $request, ReportSnapshot $report, ReportingService $reports, ReportExportService $exporter): Response
    {
        $this->authorizeSnapshot($request, $report, $reports);
        $this->audit($request, 'view_printable_report', $report, ['content_hash' => $report->content_hash]);

        return response($exporter->html($report), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-NADI-Report-Hash' => $report->content_hash,
        ]);
    }

    private function authorizeSnapshot(Request $request, ReportSnapshot $report, ReportingService $reports): void
    {
        if (! $reports->canViewSnapshot($request->user(), $report)) abort(403);
    }

    private function summary(ReportSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'reference' => $snapshot->reference,
            'report_type' => $snapshot->report_type,
            'title' => $snapshot->title,
            'period_start' => $snapshot->period_start?->toDateString(),
            'period_end' => $snapshot->period_end?->toDateString(),
            'generated_at' => $snapshot->generated_at?->toIso8601String(),
            'generated_by' => $snapshot->generator?->name,
            'department' => $snapshot->department,
            'content_hash' => $snapshot->content_hash,
        ];
    }

    private function detail(ReportSnapshot $snapshot): array
    {
        return $this->summary($snapshot) + [
            'payload' => $snapshot->payload,
            'source_manifest' => $snapshot->source_manifest,
            'schema_version' => $snapshot->schema_version,
        ];
    }

    private function audit(Request $request, string $action, ReportSnapshot $snapshot, array $changes): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => ReportSnapshot::class,
            'entity_id' => $snapshot->id,
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
