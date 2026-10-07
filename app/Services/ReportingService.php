<?php

namespace App\Services;

use App\Models\ActionItem;
use App\Models\AuditLog;
use App\Models\CertificationAppeal;
use App\Models\ComplianceFinding;
use App\Models\ComplianceObligation;
use App\Models\CorrectiveAction;
use App\Models\DataImportBatch;
use App\Models\DataSource;
use App\Models\Department;
use App\Models\KpiMeasurement;
use App\Models\ManagementReview;
use App\Models\ManagementReviewItem;
use App\Models\ReportSnapshot;
use App\Models\RiskSignal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportingService
{
    public function __construct(private readonly KpiAnalyticsService $analytics) {}

    public function catalogFor(User $user): array
    {
        $catalog = [];
        if ($user->role === 'director') {
            $catalog[] = $this->catalogItem('executive', 'Laporan Eksekutif', 'Ringkasan KPI perusahaan, operasi, risiko, tindakan, dan governance.');
        }
        if ($user->department_id || $user->role === 'director') {
            $catalog[] = $this->catalogItem('department', 'Kinerja Divisi', 'KPI, risiko, action, dan operasi untuk satu divisi.');
        }
        if ($user->hasPermission('certification.view')) {
            $catalog[] = $this->catalogItem('certification', 'Operasional Sertifikasi', 'Volume asesi, hasil keputusan, SLA penerbitan, backlog, dan pipeline.');
        }
        if ($user->hasPermission('finance.view')) {
            $catalog[] = $this->catalogItem('finance', 'Ringkasan Keuangan', 'Revenue, expense, budget, receivable, aging, dan rekonsiliasi.');
        }
        if ($user->hasPermission('it.view')) {
            $catalog[] = $this->catalogItem('it', 'Reliability TI', 'Uptime, MTTR, downtime, insiden, dan kualitas data.');
        }
        if ($user->hasPermission('governance.view')) {
            $catalog[] = $this->catalogItem('governance', 'Mutu & Kepatuhan', 'Temuan, CAPA, banding, expiry risk, dan provenance.');
        }
        if ($user->hasPermission('decisions.view')) {
            $catalog[] = $this->catalogItem('risk_action', 'Risk & Action Register', 'Risk signal, escalation, action, evidence resolusi, dan review.');
        }
        if ($user->hasPermission('decisions.review')) {
            $catalog[] = $this->catalogItem('management_review', 'Management Review', 'Snapshot satu management review beserta agenda, keputusan, signal, dan action terkait.');
        }

        return $catalog;
    }

    public function generate(User $user, string $type, CarbonInterface $period, array $options = []): ReportSnapshot
    {
        $allowed = collect($this->catalogFor($user))->pluck('type');
        if (! $allowed->contains($type)) {
            throw ValidationException::withMessages(['report_type' => 'Jenis laporan tidak tersedia untuk akun ini.']);
        }

        [$start, $end] = $this->periodRange($period);
        $department = null;
        $review = null;

        if ($type === 'department') {
            $department = $this->resolveDepartment($user, $options['department_code'] ?? null);
        } elseif (in_array($type, ['certification', 'finance', 'it'], true)) {
            $department = Department::query()->where('code', $type)->first();
        } elseif ($type === 'governance') {
            $department = Department::query()->where('code', 'quality')->first();
        } elseif ($type === 'risk_action' && $user->role !== 'director') {
            $department = $user->department;
        }
        if ($type === 'management_review') {
            $reviewId = (int) ($options['management_review_id'] ?? 0);
            $review = ManagementReview::query()->find($reviewId);
            if (! $review) {
                throw ValidationException::withMessages(['management_review_id' => 'Management review wajib dipilih.']);
            }
            $start = CarbonImmutable::parse($review->period_start)->startOfDay();
            $end = CarbonImmutable::parse($review->period_end)->endOfDay();
        }

        $payload = match ($type) {
            'executive' => $this->executivePayload($start, $end),
            'department' => $this->departmentPayload($department, $start, $end),
            'certification' => $this->domainPayload('certification', $start, $end),
            'finance' => $this->domainPayload('finance', $start, $end),
            'it' => $this->domainPayload('it', $start, $end),
            'governance' => $this->governancePayload($start, $end),
            'risk_action' => $this->decisionPayload($user, $start, $end),
            'management_review' => $this->managementReviewPayload($review),
            default => throw ValidationException::withMessages(['report_type' => 'Jenis laporan tidak dikenal.']),
        };

        $title = $this->titleFor($type, $department, $review);
        $manifestStart = $type === 'management_review' ? $start : $start->subMonths(11)->startOfMonth();
        $sourceManifest = $this->sourceManifest($manifestStart, $end, $type, $department);
        $normalizedPayload = $this->normalize([
            'meta' => [
                'schema_version' => 1,
                'report_type' => $type,
                'title' => $title,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'generated_at' => now()->toIso8601String(),
                'generated_by' => ['id' => $user->id, 'name' => $user->name, 'position' => $user->position],
                'department' => $department ? ['id' => $department->id, 'code' => $department->code, 'name' => $department->name] : null,
                'management_review_reference' => $review?->reference,
                'snapshot_notice' => 'Snapshot laporan bersifat immutable; perubahan data setelah generated_at tidak mengubah isi snapshot ini.',
            ],
            'content' => $payload,
            'kpi_configuration_snapshot' => $this->kpiConfigurationSnapshot($payload),
        ]);
        $contentHash = hash('sha256', $this->canonicalJson(['payload' => $normalizedPayload, 'source_manifest' => $sourceManifest]));

        return ReportSnapshot::create([
            'reference' => $this->nextReference($type),
            'report_type' => $type,
            'title' => $title,
            'department_id' => $department?->id,
            'management_review_id' => $review?->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'generated_at' => now(),
            'generated_by' => $user->id,
            'content_hash' => $contentHash,
            'payload' => $normalizedPayload,
            'source_manifest' => $sourceManifest,
            'schema_version' => 1,
        ]);
    }

    public function historyFor(User $user, int $limit = 40): Collection
    {
        $query = ReportSnapshot::query()->with(['generator:id,name', 'department:id,code,name'])->latest('generated_at');

        if ($user->role !== 'director') {
            $query->where(function ($q) use ($user): void {
                // Default-deny: only explicitly authorized report types/scopes are added below.
                $q->whereRaw('1 = 0');

                if ($user->department_id) {
                    $q->orWhere(function ($departmentQuery) use ($user): void {
                        $departmentQuery->where('report_type', 'department')
                            ->where('department_id', $user->department_id);
                    });
                }

                if ($user->hasPermission('certification.view')) {
                    $q->orWhere('report_type', 'certification');
                }
                if ($user->hasPermission('finance.view')) {
                    $q->orWhere('report_type', 'finance');
                }
                if ($user->hasPermission('it.view')) {
                    $q->orWhere('report_type', 'it');
                }
                if ($user->hasPermission('governance.view')) {
                    $q->orWhere('report_type', 'governance');
                }
                if ($user->hasPermission('decisions.view') && $user->department_id) {
                    $q->orWhere(function ($riskQuery) use ($user): void {
                        $riskQuery->where('report_type', 'risk_action')
                            ->where('department_id', $user->department_id);
                    });
                }
                if ($user->hasPermission('decisions.review')) {
                    $q->orWhere('report_type', 'management_review');
                }
            });
        }

        return $query->limit($limit)->get();
    }

    public function canViewSnapshot(User $user, ReportSnapshot $snapshot): bool
    {
        if ($user->role === 'director') {
            return true;
        }

        return match ($snapshot->report_type) {
            'executive' => false,
            'department' => (bool) $snapshot->department_id
                && $snapshot->department_id === $user->department_id,
            'certification' => $user->hasPermission('certification.view'),
            'finance' => $user->hasPermission('finance.view'),
            'it' => $user->hasPermission('it.view'),
            'governance' => $user->hasPermission('governance.view'),
            'risk_action' => $user->hasPermission('decisions.view')
                && (bool) $snapshot->department_id
                && $snapshot->department_id === $user->department_id,
            'management_review' => $user->hasPermission('decisions.review'),
            default => false,
        };
    }

    private function executivePayload(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $overview = $this->analytics->overview(null, $end);
        $decisions = $this->decisionData(null, $start, $end);

        return [
            'overview' => $overview,
            'certification' => $this->analytics->certification($end),
            'finance' => $this->analytics->finance($end),
            'it' => $this->analytics->it($end),
            'governance' => $this->governanceData($start, $end),
            'decisions' => $decisions,
            'signals' => $decisions['signals'],
            'management_reviews' => $decisions['management_reviews'],
        ];
    }

    private function departmentPayload(Department $department, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $decisions = $this->decisionData($department->id, $start, $end);
        $content = [
            'department' => ['id' => $department->id, 'code' => $department->code, 'name' => $department->name],
            'overview' => $this->analytics->overview($department->code, $end),
            'decisions' => $decisions,
            'signals' => $decisions['signals'],
            'management_reviews' => $decisions['management_reviews'],
        ];
        if ($department->code === 'certification') {
            $content['operations'] = $this->analytics->certification($end);
        }
        if ($department->code === 'finance') {
            $content['operations'] = $this->analytics->finance($end);
        }
        if ($department->code === 'it') {
            $content['operations'] = $this->analytics->it($end);
        }
        if ($department->code === 'quality') {
            $content['operations'] = $this->governanceData($start, $end);
        }

        return $content;
    }

    private function domainPayload(string $domain, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $decisions = $this->decisionData(Department::query()->where('code', $domain)->value('id'), $start, $end);

        return [
            'overview' => $this->analytics->overview($domain, $end),
            'operations' => match ($domain) {
                'certification' => $this->analytics->certification($end),
                'finance' => $this->analytics->finance($end),
                'it' => $this->analytics->it($end),
            },
            'decisions' => $decisions,
            'signals' => $decisions['signals'],
            'management_reviews' => $decisions['management_reviews'],
        ];
    }

    private function governancePayload(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return ['governance' => $this->governanceData($start, $end)];
    }

    private function decisionPayload(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $departmentId = $user->role === 'director' || $user->department?->code === 'quality' ? null : $user->department_id;
        $decisions = $this->decisionData($departmentId, $start, $end);

        return [
            'decisions' => $decisions,
            'signals' => $decisions['signals'],
            'actions' => $decisions['actions'],
            'management_reviews' => $decisions['management_reviews'],
        ];
    }

    private function managementReviewPayload(ManagementReview $review): array
    {
        $review->load(['creator:id,name,position', 'approver:id,name,position', 'items.signal.department', 'items.signal.kpi', 'items.action.department', 'items.action.kpi']);

        return [
            'review' => $this->normalize($review),
            'signals' => $review->items->pluck('signal')->filter()->unique('id')->values(),
            'actions' => $review->items->pluck('action')->filter()->unique('id')->values(),
        ];
    }

    private function governanceData(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $findings = ComplianceFinding::query()
            ->where('opened_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $start))
            ->orderByDesc('opened_at')->get();
        $actions = CorrectiveAction::query()
            ->whereIn('compliance_finding_id', $findings->pluck('id'))
            ->where('created_at', '<=', $end)
            ->get();
        $appeals = CertificationAppeal::query()->with('batch:id,code')
            ->where('received_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('decision_at')->orWhere('decision_at', '>=', $start))
            ->orderByDesc('received_at')->get();
        $obligations = ComplianceObligation::query()
            ->where(function ($query) use ($end): void {
                $query->whereDate('valid_from', '<=', $end->toDateString())
                    ->orWhere(function ($fallback) use ($end): void {
                        $fallback->whereNull('valid_from')->where('created_at', '<=', $end);
                    });
            })
            ->orderBy('valid_until')->get();
        $horizon = $end->addDays(90);

        $findingAudits = $this->auditsByEntityAsOf(ComplianceFinding::class, $findings->pluck('id'), $end);
        $actionAudits = $this->auditsByEntityAsOf(CorrectiveAction::class, $actions->pluck('id'), $end);
        $appealAudits = $this->auditsByEntityAsOf(CertificationAppeal::class, $appeals->pluck('id'), $end);
        $actionsByFinding = $actions->groupBy('compliance_finding_id');

        $findingRows = $findings->map(function (ComplianceFinding $finding) use ($end, $findingAudits, $actionsByFinding): array {
            $logs = $findingAudits->get($finding->id, collect());
            $visibleActions = $actionsByFinding->get($finding->id, collect());
            $statusAsOf = $this->auditedStatusAsOf($logs, 'open');
            if ($statusAsOf === 'open' && $visibleActions->isNotEmpty()) {
                $statusAsOf = 'in_progress';
            }
            if ($finding->closed_at && $finding->closed_at->lte($end)) {
                $statusAsOf = 'closed';
            } elseif ($statusAsOf === 'closed') {
                $statusAsOf = $visibleActions->isNotEmpty() ? 'in_progress' : 'open';
            }

            $row = $finding->toArray();
            $row['status'] = $statusAsOf;
            $row['status_as_of'] = $statusAsOf;
            $row['closure_evidence'] = $this->auditedFieldAsOf($logs, 'closure_evidence');
            $row['closed_at'] = $finding->closed_at && $finding->closed_at->lte($end)
                ? $finding->closed_at->toIso8601String()
                : null;
            $row['closed_at_as_of'] = $row['closed_at'];
            $row['actions'] = [];

            return $row;
        });
        $actionRows = $actions->map(function (CorrectiveAction $action) use ($end, $actionAudits): array {
            $logs = $actionAudits->get($action->id, collect());
            $statusAsOf = $this->auditedStatusAsOf($logs, 'open');
            if ($action->completed_at && $action->completed_at->lte($end)) {
                $statusAsOf = 'completed';
            } elseif ($statusAsOf === 'completed') {
                $statusAsOf = 'in_progress';
            }

            $row = $action->toArray();
            $row['status'] = $statusAsOf;
            $row['status_as_of'] = $statusAsOf;
            $row['evidence'] = $this->auditedFieldAsOf($logs, 'evidence');
            $row['completed_at'] = $action->completed_at && $action->completed_at->lte($end)
                ? $action->completed_at->toIso8601String()
                : null;
            $row['completed_at_as_of'] = $row['completed_at'];

            return $row;
        });
        $appealRows = $appeals->map(function (CertificationAppeal $appeal) use ($end, $appealAudits): array {
            $logs = $appealAudits->get($appeal->id, collect());
            $decidedAsOf = $appeal->decision_at && $appeal->decision_at->lte($end);
            $statusAsOf = $this->auditedStatusAsOf($logs, 'received');
            if (! $decidedAsOf && in_array($statusAsOf, ['decided', 'closed'], true)) {
                $statusAsOf = 'reviewing';
            }

            $row = $appeal->toArray();
            $row['status'] = $statusAsOf;
            $row['status_as_of'] = $statusAsOf;
            $row['decision'] = $decidedAsOf ? $this->auditedFieldAsOf($logs, 'decision') : null;
            $row['resolution_summary'] = $decidedAsOf ? $this->auditedFieldAsOf($logs, 'resolution_summary') : null;
            $row['decision_at'] = $decidedAsOf ? $appeal->decision_at->toIso8601String() : null;
            $row['decision_at_as_of'] = $row['decision_at'];

            return $row;
        });

        return [
            'summary' => [
                'open_findings' => $findingRows->where('status_as_of', '!=', 'closed')->count(),
                'overdue_capa' => $actionRows
                    ->where('status_as_of', '!=', 'completed')
                    ->filter(fn ($row) => ! empty($row['due_date']) && CarbonImmutable::parse($row['due_date'])->lt($end->startOfDay()))
                    ->count(),
                'open_appeals' => $appealRows->whereNotIn('status_as_of', ['decided', 'closed'])->count(),
                'obligations_expiring_90d' => $obligations->filter(fn ($row) => $row->status !== 'closed' && $row->valid_until && $row->valid_until->betweenIncluded($end->startOfDay(), $horizon))->count(),
            ],
            'findings' => $findingRows,
            'corrective_actions' => $actionRows,
            'appeals' => $appealRows,
            'obligations' => $obligations,
        ];
    }

    private function decisionData(?int $departmentId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $signals = RiskSignal::query()->with(['department:id,code,name', 'kpi:id,code,name'])
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->where('detected_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $start))
            ->orderByDesc('detected_at')->get();
        $actions = ActionItem::query()->with(['department:id,code,name', 'kpi:id,code,name', 'riskSignal:id,fingerprint,title,severity,status'])
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->where('created_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', $start))
            ->orderBy('due_date')->get();
        $reviews = ManagementReview::query()->with(['items.signal:id,title,severity,status', 'items.action:id,title,status'])
            ->where('created_at', '<=', $end)
            ->where('period_start', '<=', $end->toDateString())
            ->where('period_end', '>=', $start->toDateString())
            ->orderByDesc('period_end')->get();
        $reviewAudits = $this->auditsForEntities(ManagementReview::class, $reviews->pluck('id'));
        $reviewItems = $reviews->pluck('items')->flatten(1)->filter(fn ($item) => $item->created_at?->lte($end));
        $reviewItemAudits = $this->auditsForEntities(ManagementReviewItem::class, $reviewItems->pluck('id'));

        $signalRows = $signals->map(function (RiskSignal $signal) use ($end): array {
            $statusAsOf = $this->riskStatusAsOf($signal, $end);
            $acknowledgedVisible = $signal->acknowledged_at && $signal->acknowledged_at->lte($end);
            $escalatedVisible = $signal->escalated_at && $signal->escalated_at->lte($end);
            $resolvedVisible = $signal->resolved_at && $signal->resolved_at->lte($end);
            $row = $signal->toArray();
            $row['status'] = $statusAsOf;
            $row['status_as_of'] = $statusAsOf;
            $row['acknowledged_at'] = $acknowledgedVisible ? $signal->acknowledged_at->toIso8601String() : null;
            $row['acknowledged_by'] = $acknowledgedVisible ? $signal->acknowledged_by : null;
            $row['escalated_at'] = $escalatedVisible ? $signal->escalated_at->toIso8601String() : null;
            $row['escalated_by'] = $escalatedVisible ? $signal->escalated_by : null;
            $row['escalation_level'] = $escalatedVisible ? $signal->escalation_level : 0;
            $row['resolved_at'] = $resolvedVisible ? $signal->resolved_at->toIso8601String() : null;
            $row['resolved_at_as_of'] = $row['resolved_at'];
            $row['resolved_by'] = $resolvedVisible ? $signal->resolved_by : null;
            $row['resolution_note'] = $resolvedVisible ? $signal->resolution_note : null;

            return $row;
        });
        $actionRows = $actions->map(function (ActionItem $action) use ($end): array {
            $statusAsOf = $this->actionStatusAsOf($action, $end);
            $acknowledgedVisible = $action->acknowledged_at && $action->acknowledged_at->lte($end);
            $escalatedVisible = $action->escalated_at && $action->escalated_at->lte($end);
            $completedVisible = $action->completed_at && $action->completed_at->lte($end);
            $row = $action->toArray();
            $row['status'] = $statusAsOf;
            $row['status_as_of'] = $statusAsOf;
            $row['acknowledged_at'] = $acknowledgedVisible ? $action->acknowledged_at->toIso8601String() : null;
            $row['acknowledged_by'] = $acknowledgedVisible ? $action->acknowledged_by : null;
            $row['escalated_at'] = $escalatedVisible ? $action->escalated_at->toIso8601String() : null;
            $row['escalated_by'] = $escalatedVisible ? $action->escalated_by : null;
            $row['escalation_level'] = $escalatedVisible ? $action->escalation_level : 0;
            $row['completed_at'] = $completedVisible ? $action->completed_at->toIso8601String() : null;
            $row['completed_at_as_of'] = $row['completed_at'];
            $row['resolution_note'] = $completedVisible ? $action->resolution_note : null;
            $row['resolution_evidence'] = $completedVisible ? $action->resolution_evidence : null;

            return $row;
        });

        $reviewRows = $reviews->map(function (ManagementReview $review) use ($end, $reviewAudits, $reviewItemAudits): array {
            $logs = collect($reviewAudits->get($review->id, []));
            $row = $review->toArray();
            foreach (['status', 'meeting_at', 'summary', 'decisions'] as $field) {
                $row[$field] = $this->auditedFieldRollbackAsOf($logs, $field, $row[$field] ?? null, $end);
            }
            $approvedVisible = $review->approved_at && $review->approved_at->lte($end);
            $closedVisible = $review->closed_at && $review->closed_at->lte($end);
            $row['approved_at'] = $approvedVisible ? $review->approved_at->toIso8601String() : null;
            $row['approved_by'] = $approvedVisible ? $review->approved_by : null;
            $row['closed_at'] = $closedVisible ? $review->closed_at->toIso8601String() : null;
            $row['items'] = $review->items
                ->filter(fn ($item) => $item->created_at?->lte($end))
                ->map(function (ManagementReviewItem $item) use ($end, $reviewItemAudits): array {
                    $itemLogs = collect($reviewItemAudits->get($item->id, []));
                    $itemRow = $item->toArray();
                    foreach (['decision', 'owner_name', 'due_date', 'status'] as $field) {
                        $itemRow[$field] = $this->auditedFieldRollbackAsOf($itemLogs, $field, $itemRow[$field] ?? null, $end);
                    }

                    return $itemRow;
                })->values()->all();

            return $row;
        });

        return [
            'summary' => [
                'signals' => $signalRows->count(),
                'active_signals' => $signalRows->whereNotIn('status_as_of', ['resolved', 'dismissed'])->count(),
                'critical_signals' => $signalRows->where('severity', 'critical')->count(),
                'open_actions' => $actionRows->where('status_as_of', '!=', 'completed')->count(),
                'overdue_actions' => $actionRows
                    ->where('status_as_of', '!=', 'completed')
                    ->filter(fn ($row) => ! empty($row['due_date']) && CarbonImmutable::parse($row['due_date'])->lt($end->startOfDay()))
                    ->count(),
                'management_reviews' => $reviewRows->count(),
            ],
            'signals' => $signalRows,
            'actions' => $actionRows,
            'management_reviews' => $reviewRows,
        ];
    }

    private function auditsForEntities(string $entityType, Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return AuditLog::query()
            ->where('entity_type', $entityType)
            ->whereIn('entity_id', $ids->all())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('entity_id');
    }

    private function auditedFieldRollbackAsOf(Collection $logs, string $field, mixed $current, CarbonImmutable $end): mixed
    {
        $value = $current;
        foreach ($logs->filter(fn ($log) => $log->created_at && $log->created_at->gt($end))->sortByDesc(fn ($log) => sprintf('%020d-%020d', $log->created_at->getTimestamp(), $log->id)) as $log) {
            $changes = (array) ($log->changes ?? []);
            if (is_array($changes['before'] ?? null) && array_key_exists($field, $changes['before'])) {
                $value = $changes['before'][$field];
            }
        }

        return $value;
    }

    private function auditsByEntityAsOf(string $entityType, Collection $ids, CarbonImmutable $end): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return AuditLog::query()
            ->where('entity_type', $entityType)
            ->whereIn('entity_id', $ids->all())
            ->where('created_at', '<=', $end)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('entity_id');
    }

    private function auditedStatusAsOf(Collection $logs, string $initial): string
    {
        $status = $initial;
        foreach ($logs as $log) {
            $changes = (array) ($log->changes ?? []);
            $candidate = data_get($changes, 'after.status', $changes['status'] ?? null);
            if (is_string($candidate) && $candidate !== '') {
                $status = $candidate;
            }
        }

        return $status;
    }

    private function auditedFieldAsOf(Collection $logs, string $field, mixed $default = null): mixed
    {
        $value = $default;
        foreach ($logs as $log) {
            $changes = (array) ($log->changes ?? []);
            if (is_array($changes['after'] ?? null) && array_key_exists($field, $changes['after'])) {
                $value = $changes['after'][$field];
            } elseif (array_key_exists($field, $changes)) {
                $value = $changes[$field];
            }
        }

        return $value;
    }

    private function riskStatusAsOf(RiskSignal $signal, CarbonImmutable $end): string
    {
        if ($signal->resolved_at && $signal->resolved_at->lte($end)) {
            return in_array($signal->status, ['resolved', 'dismissed'], true) ? $signal->status : 'resolved';
        }
        if ($signal->escalated_at && $signal->escalated_at->lte($end)) {
            return 'in_progress';
        }
        if ($signal->acknowledged_at && $signal->acknowledged_at->lte($end)) {
            return 'acknowledged';
        }

        return 'open';
    }

    private function actionStatusAsOf(ActionItem $action, CarbonImmutable $end): string
    {
        if ($action->completed_at && $action->completed_at->lte($end)) {
            return 'completed';
        }
        if ($action->escalated_at && $action->escalated_at->lte($end)) {
            return 'in_progress';
        }
        if ($action->acknowledged_at && $action->acknowledged_at->lte($end)) {
            return 'in_progress';
        }

        return 'open';
    }

    private function sourceManifest(CarbonImmutable $start, CarbonImmutable $end, string $reportType, ?Department $department): array
    {
        $datasetTypes = $this->manifestDatasetTypes($reportType, $department);
        $customBatchIds = collect();

        if ($reportType === 'executive' || $reportType === 'department') {
            $customBatchIds = KpiMeasurement::query()
                ->whereNotNull('data_import_batch_id')
                ->whereBetween('period', [$start->toDateString(), $end->toDateString()])
                ->when(
                    $reportType === 'department' && $department,
                    fn ($query) => $query->whereHas('definition', fn ($definition) => $definition->where('department_id', $department->id))
                )
                ->pluck('data_import_batch_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        $imports = DataImportBatch::query()
            ->with('source:id,code,name,source_type,authority_rank,owner_name,location')
            ->when($datasetTypes !== null, function ($query) use ($datasetTypes, $customBatchIds): void {
                $query->where(function ($scope) use ($datasetTypes, $customBatchIds): void {
                    if ($datasetTypes !== []) {
                        $scope->whereIn('dataset_type', $datasetTypes);
                    } else {
                        $scope->whereRaw('1 = 0');
                    }
                    if ($customBatchIds->isNotEmpty()) {
                        $scope->orWhereIn('id', $customBatchIds->all());
                    }
                });
            })
            ->where(function ($query) use ($start, $end, $customBatchIds): void {
                $query->whereBetween('imported_at', [$start, $end]);
                if ($customBatchIds->isNotEmpty()) {
                    // A backdated KPI measurement can legitimately be imported after report_end.
                    // Include the actual batch referenced by the measurement so provenance remains complete.
                    $query->orWhereIn('id', $customBatchIds->all());
                }
            })
            ->orderBy('imported_at')
            ->get();

        $sourceIds = $imports->pluck('data_source_id')->filter()->unique()->values();
        $sources = $sourceIds->isEmpty()
            ? collect()
            : DataSource::query()
                ->whereIn('id', $sourceIds->all())
                ->orderByDesc('authority_rank')
                ->get(['id', 'code', 'name', 'source_type', 'authority_rank', 'owner_name', 'location', 'is_active']);

        return $this->normalize([
            'scope' => [
                'report_type' => $reportType,
                'department_code' => $department?->code,
                'dataset_types' => $datasetTypes,
            ],
            'data_sources' => $sources,
            'import_batches' => $imports->map(fn ($row) => [
                'reference' => $row->reference,
                'dataset_type' => $row->dataset_type,
                'dataset_name' => $row->dataset_name,
                'file_name' => $row->file_name,
                'sha256' => $row->sha256,
                'imported_at' => $row->imported_at?->toIso8601String(),
                'accepted_rows' => $row->accepted_rows,
                'rejected_rows' => $row->rejected_rows,
                'reconciliation_status' => $row->reconciliation_status,
                'source' => $row->source ? [
                    'code' => $row->source->code,
                    'name' => $row->source->name,
                    'source_type' => $row->source->source_type,
                    'authority_rank' => $row->source->authority_rank,
                ] : null,
            ])->values(),
        ]);
    }

    /** @return list<string>|null null means all integration datasets are in scope. */
    private function manifestDatasetTypes(string $reportType, ?Department $department): ?array
    {
        $domain = match ($reportType) {
            'certification', 'finance', 'it' => $reportType,
            'department' => $department?->code,
            default => null,
        };

        if ($reportType === 'executive') {
            return null;
        }

        return match ($domain) {
            'certification' => ['certification_schemes', 'tuks', 'assessors', 'certification_batches', 'certificate_issuances'],
            'finance' => ['finance_invoices', 'finance_payments', 'financial_records'],
            'it' => ['it_services', 'it_incidents', 'data_quality_runs'],
            default => [],
        };
    }

    private function kpiConfigurationSnapshot(array $payload): array
    {
        $kpis = collect();
        $walk = function ($value) use (&$walk, &$kpis): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === 'kpis' && is_array($child) && array_is_list($child)) {
                    foreach ($child as $row) {
                        if (is_array($row) && isset($row['code'])) {
                            $kpis->push($row);
                        }
                    }
                }
                $walk($child);
            }
        };
        $walk($this->normalize($payload));

        return $kpis->unique('code')->sortBy('code')->map(fn ($kpi) => [
            'code' => $kpi['code'],
            'name' => $kpi['name'] ?? null,
            'target' => $kpi['target'] ?? null,
            'warning_threshold' => $kpi['warning_threshold'] ?? null,
            'weight' => $kpi['weight'] ?? null,
            'owner_name' => $kpi['owner_name'] ?? null,
            'effective_from' => $kpi['effective_from'] ?? null,
            'actual' => $kpi['actual'] ?? null,
            'status' => $kpi['status'] ?? null,
            'source_type' => $kpi['source_type'] ?? null,
            'data_source' => $kpi['data_source'] ?? null,
        ])->values()->all();
    }

    private function resolveDepartment(User $user, ?string $code): Department
    {
        if ($user->role !== 'director') {
            if (! $user->department) {
                throw ValidationException::withMessages(['department_code' => 'Akun tidak memiliki divisi.']);
            }

            return $user->department;
        }
        if (! $code) {
            throw ValidationException::withMessages(['department_code' => 'Divisi wajib dipilih untuk laporan kinerja divisi.']);
        }

        return Department::query()->where('code', $code)->where('code', '!=', 'leadership')->firstOrFail();
    }

    private function periodRange(CarbonInterface $period): array
    {
        $requestedEnd = CarbonImmutable::instance($period)->endOfMonth();
        $now = CarbonImmutable::now();
        $end = $requestedEnd->greaterThan($now) ? $now : $requestedEnd;

        return [$end->startOfMonth(), $end];
    }

    private function titleFor(string $type, ?Department $department, ?ManagementReview $review): string
    {
        return match ($type) {
            'executive' => 'Executive KPI & Management Report',
            'department' => 'Department Performance — '.$department?->name,
            'certification' => 'Certification Operational Report',
            'finance' => 'Finance Performance Report',
            'it' => 'IT Reliability & Data Quality Report',
            'governance' => 'Governance & Compliance Report',
            'risk_action' => 'Risk & Action Register',
            'management_review' => 'Management Review — '.$review?->reference,
        };
    }

    private function nextReference(string $type): string
    {
        $prefix = strtoupper(substr(str_replace('_', '', $type), 0, 8));

        return sprintf('RPT-%s-%s-%s', $prefix, now()->format('YmdHis'), strtoupper(Str::random(5)));
    }

    private function catalogItem(string $type, string $label, string $description): array
    {
        return compact('type', 'label', 'description');
    }

    private function normalize(mixed $value): array
    {
        return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?: [];
    }

    private function canonicalJson(array $payload): string
    {
        $sort = function (&$value) use (&$sort): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as &$child) {
                $sort($child);
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
        };
        $copy = $payload;
        $sort($copy);

        return json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
