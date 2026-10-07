<?php

namespace App\Services;

use App\Models\ActionItem;
use App\Models\AuditLog;
use App\Models\CertificateIssuance;
use App\Models\CertificationBatch;
use App\Models\DataQualityRun;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancialRecord;
use App\Models\ItIncident;
use App\Models\ItService;
use App\Models\KpiDefinition;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class KpiAnalyticsService
{
    public function overview(?string $departmentCode = null, ?CarbonInterface $period = null): array
    {
        $end = $this->reportingEnd($period);
        $start = $end->startOfMonth()->subMonths(11);
        $definitions = KpiDefinition::query()
            ->with(['department', 'configurations', 'measurements' => fn ($query) => $query->whereBetween('period', [$start, $end])->orderBy('period')])
            ->whereNull('archived_at')
            ->when($departmentCode, fn ($query) => $query->whereHas('department', fn ($q) => $q->where('code', $departmentCode)))
            ->get()
            ->filter(function (KpiDefinition $definition) use ($end) {
                $config = $definition->configurationFor($end);

                return $config
                    ? (bool) $config->is_active
                    : (bool) $definition->is_active;
            });

        $cards = $definitions->map(fn (KpiDefinition $definition) => $this->formatKpi($definition, $end));
        $weight = max(1, $cards->sum('weight'));
        $overall = round($cards->sum(fn ($kpi) => $kpi['score'] * $kpi['weight']) / $weight, 1);

        $critical = $cards->where('status', 'critical')->values();
        $watch = $cards->where('status', 'watch')->values();
        $actions = ActionItem::query()
            ->when($departmentCode, fn ($query) => $query->whereHas('department', fn ($departmentQuery) => $departmentQuery->where('code', $departmentCode)))
            ->where('created_at', '<=', $end)
            ->get();
        $actionRows = $actions->map(fn (ActionItem $action): array => [
            'status_as_of' => $this->actionStatusAsOf($action, $end),
            'due_date' => $action->due_date?->toDateString(),
        ]);

        return [
            'overall_score' => $overall,
            'overall_status' => $this->statusFromScore($overall),
            'kpis' => $cards->values(),
            'department_scores' => $cards->groupBy('department.code')->map(function (Collection $items) {
                $weight = max(1, $items->sum('weight'));

                return round($items->sum(fn ($item) => $item['score'] * $item['weight']) / $weight, 1);
            })->values(),
            'departments' => $cards->groupBy('department.code')->map(function (Collection $items) {
                $weight = max(1, $items->sum('weight'));

                return [
                    'code' => $items->first()['department']['code'],
                    'name' => $items->first()['department']['name'],
                    'color' => $items->first()['department']['color'],
                    'score' => round($items->sum(fn ($item) => $item['score'] * $item['weight']) / $weight, 1),
                    'critical_count' => $items->where('status', 'critical')->count(),
                ];
            })->values(),
            'signals' => $this->signals($critical, $watch),
            'open_actions' => $actionRows->where('status_as_of', '!=', 'completed')->count(),
            'overdue_actions' => $actionRows
                ->where('status_as_of', '!=', 'completed')
                ->filter(fn (array $row): bool => ! empty($row['due_date']) && CarbonImmutable::parse($row['due_date'])->lt($end->startOfDay()))
                ->count(),
            'last_updated_at' => $this->lastUpdatedAt($definitions, $departmentCode, $end),
            'period' => $this->periodMetadata($start, $end),
            'prototype_notice' => config('nadi.demo_mode')
                ? 'Data demonstrasi sintetis. Bukan data operasional perusahaan.'
                : null,
        ];
    }

    public function certification(?CarbonInterface $period = null): array
    {
        $end = $this->reportingEnd($period);
        $start = $end->startOfMonth()->subMonths(11);
        $batches = CertificationBatch::with(['scheme', 'tuk', 'assessor', 'issuances'])
            ->whereBetween('assessment_date', [$start, $end])
            ->orderByDesc('assessment_date')
            ->get();
        $batchAudits = $this->certificationAudits($batches->pluck('id'));
        $batchRows = $batches->map(fn (CertificationBatch $batch) => $this->certificationBatchAsOf(
            $batch,
            $end,
            collect($batchAudits->get($batch->id, [])),
        ));

        $historicalLifecycleIds = $this->certificationAuditCandidateIds($start, $end, [
            'decision_at', 'certificate_due_at',
        ]);
        $lifecycleBatches = CertificationBatch::with('issuances')
            ->where(function ($query) use ($start, $end, $historicalLifecycleIds) {
                $query->whereBetween('decision_at', [$start, $end])
                    ->orWhereBetween('certificate_due_at', [$start, $end]);
                if ($historicalLifecycleIds->isNotEmpty()) {
                    $query->orWhereIn('id', $historicalLifecycleIds->all());
                }
            })
            ->get();
        $lifecycleAudits = $this->certificationAudits($lifecycleBatches->pluck('id'));
        $lifecycleRows = $lifecycleBatches->map(fn (CertificationBatch $batch) => $this->certificationBatchAsOf(
            $batch,
            $end,
            collect($lifecycleAudits->get($batch->id, [])),
        ));

        $historicalBacklogIds = $this->certificationAuditCandidateIds(null, $end, ['decision_at']);
        $backlogBatches = CertificationBatch::with('issuances')
            ->where(function ($query) use ($end, $historicalBacklogIds) {
                $query->where(function ($current) use ($end) {
                    $current->whereNotNull('decision_at')->where('decision_at', '<=', $end);
                });
                if ($historicalBacklogIds->isNotEmpty()) {
                    $query->orWhereIn('id', $historicalBacklogIds->all());
                }
            })
            ->get();
        $backlogAudits = $this->certificationAudits($backlogBatches->pluck('id'));
        $backlogRows = $backlogBatches->map(fn (CertificationBatch $batch) => $this->certificationBatchAsOf(
            $batch,
            $end,
            collect($backlogAudits->get($batch->id, [])),
        ));

        $decided = $lifecycleRows->filter(fn (array $row) => ! empty($row['decision_at'])
            && CarbonImmutable::parse($row['decision_at'])->betweenIncluded($start, $end));
        $assessed = $decided->sum(fn (array $row) => (int) $row['passed'] + (int) $row['failed']);
        $slaCohort = $lifecycleRows->filter(fn (array $row) => ! empty($row['certificate_due_at'])
            && CarbonImmutable::parse($row['certificate_due_at'])->betweenIncluded($start, $end));
        $slaEligible = $slaCohort->sum('passed');
        $slaOnTime = $slaCohort->sum(fn (array $row) => min(
            (int) $row['passed'],
            collect($row['issuances'] ?? [])->filter(fn (array $issuance) => ! empty($issuance['issued_at'])
                && CarbonImmutable::parse($issuance['issued_at'])->lte(CarbonImmutable::parse($row['certificate_due_at'])))->sum('issued_count')
        ));
        $certificateBacklog = $backlogRows->sum(fn (array $row) => max(0, (int) $row['passed'] - (int) $row['certificates_issued']));
        $overdueCertificates = $backlogRows->sum(fn (array $row) => ! empty($row['certificate_due_at'])
            && CarbonImmutable::parse($row['certificate_due_at'])->lt($end)
            ? max(0, (int) $row['passed'] - (int) $row['certificates_issued'])
            : 0);
        $months = collect(range(11, 0))->map(fn ($offset) => CarbonImmutable::instance($end)->startOfMonth()->subMonths($offset));

        return [
            'summary' => [
                'asesi' => $batchRows->sum('total_assesi'),
                'pass_rate' => $assessed ? round($decided->sum('passed') / $assessed * 100, 1) : 0,
                'certificate_sla' => $slaEligible ? round($slaOnTime / $slaEligible * 100, 1) : 0,
                'active_batches' => $batchRows->where('status_as_of', '!=', 'completed')->count(),
                'certificate_backlog' => $certificateBacklog,
                'overdue_certificates' => $overdueCertificates,
            ],
            'monthly' => $months->map(function (CarbonImmutable $month) use ($batchRows): array {
                $matches = $batchRows->filter(fn ($row) => CarbonImmutable::parse($row['assessment_date'])->format('Y-m') === $month->format('Y-m'));

                return [
                    'period' => $month->format('M y'),
                    'asesi' => $matches->sum('total_assesi'),
                    'kompeten' => $matches->sum('passed'),
                    'belum_kompeten' => $matches->sum('failed'),
                ];
            }),
            'pipeline' => collect(['planned', 'document_review', 'assessment', 'decision', 'completed'])
                ->map(fn ($status) => ['status' => $status, 'count' => $batchRows->where('status_as_of', $status)->count()]),
            'schemes' => $batchRows->groupBy(fn ($row) => data_get($row, 'scheme.name', 'Tanpa skema'))->map(fn ($items, $name) => [
                'name' => $name,
                'asesi' => $items->sum('total_assesi'),
                'pass_rate' => ($items->sum('passed') + $items->sum('failed')) > 0
                    ? round($items->sum('passed') / ($items->sum('passed') + $items->sum('failed')) * 100, 1) : 0,
            ])->sortByDesc('asesi')->values()->take(6),
            'batches' => $batchRows->take(12)->values(),
            'period' => $this->periodMetadata($start, $end),
        ];
    }

    public function finance(?CarbonInterface $period = null): array
    {
        $end = $this->reportingEnd($period);
        $start = $end->startOfMonth()->subMonths(11);
        $records = FinancialRecord::with('reversals')
            ->whereBetween('recorded_on', [$start, $end])
            ->orderByDesc('recorded_on')
            ->orderByDesc('id')
            ->get();

        $revenue = $this->netLedgerAmount($records, 'revenue');
        $expense = $this->netLedgerAmount($records, 'expense');
        $budget = $this->netLedgerAmount($records, 'budget');
        $budgetVariance = $budget > 0 ? round(abs($expense - $budget) / $budget * 100, 1) : 0;

        $invoices = FinanceInvoice::with(['payments', 'revenueRecord.reversals'])
            ->where('issued_on', '<=', $end)
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();
        $invoiceRows = $invoices->map(fn (FinanceInvoice $invoice) => $this->invoiceAsOf($invoice, $end));
        $activeInvoiceRows = $invoiceRows->where('is_active_as_of', true);
        $openInvoiceRows = $activeInvoiceRows->where('outstanding', '>', 0.005);

        $paymentsReceived = FinancePayment::query()
            ->whereBetween('paid_on', [$start, $end])
            ->where(fn ($query) => $query->whereNull('reversed_at')->orWhere('reversed_at', '>', $end))
            ->sum('amount');

        $activeInvoiceAmount = $activeInvoiceRows->sum('amount');
        $invoiceLedgerRevenue = FinancialRecord::query()
            ->where('recorded_on', '<=', $end)
            ->where('type', 'revenue')
            ->whereIn('source_type', ['finance_invoice', 'integration_invoice', 'invoice_void'])
            ->get()
            ->sum(fn ($record) => $this->signedAmount($record));
        $manualRevenue = $records
            ->where('type', 'revenue')
            ->reject(fn ($record) => in_array($record->source_type, ['finance_invoice', 'integration_invoice', 'invoice_void'], true))
            ->sum(fn ($record) => $this->signedAmount($record));

        return [
            'summary' => [
                'revenue' => round($revenue, 2),
                'expense' => round($expense, 2),
                'budget' => round($budget, 2),
                'operating_margin' => $revenue ? round(($revenue - $expense) / $revenue * 100, 1) : 0,
                'budget_absorption' => $budget ? round($expense / $budget * 100, 1) : 0,
                'budget_variance' => $budgetVariance,
                'payments_received' => round((float) $paymentsReceived, 2),
                'open_receivable' => round((float) $openInvoiceRows->sum('outstanding'), 2),
                'overdue_receivable' => round((float) $openInvoiceRows->where('days_overdue', '>', 0)->sum('outstanding'), 2),
                'open_invoices' => $openInvoiceRows->count(),
            ],
            'monthly' => $this->monthlySeries($records, 'recorded_on', [
                'revenue' => fn ($items) => round($this->netLedgerAmount($items, 'revenue'), 2),
                'expense' => fn ($items) => round($this->netLedgerAmount($items, 'expense'), 2),
                'budget' => fn ($items) => round($this->netLedgerAmount($items, 'budget'), 2),
            ], $end),
            'expense_mix' => $records->where('type', 'expense')->groupBy('category')->map(fn ($items, $category) => [
                'category' => $category,
                'amount' => round($items->sum(fn ($record) => $this->signedAmount($record)), 2),
            ])->filter(fn ($item) => abs($item['amount']) > 0.005)->sortByDesc('amount')->values(),
            'aging' => collect([
                ['bucket' => 'Belum jatuh tempo', 'amount' => $openInvoiceRows->where('days_overdue', 0)->sum('outstanding')],
                ['bucket' => '1–30 hari', 'amount' => $openInvoiceRows->filter(fn ($row) => $row['days_overdue'] >= 1 && $row['days_overdue'] <= 30)->sum('outstanding')],
                ['bucket' => '31–60 hari', 'amount' => $openInvoiceRows->filter(fn ($row) => $row['days_overdue'] >= 31 && $row['days_overdue'] <= 60)->sum('outstanding')],
                ['bucket' => '61–90 hari', 'amount' => $openInvoiceRows->filter(fn ($row) => $row['days_overdue'] >= 61 && $row['days_overdue'] <= 90)->sum('outstanding')],
                ['bucket' => '>90 hari', 'amount' => $openInvoiceRows->where('days_overdue', '>', 90)->sum('outstanding')],
            ])->map(fn ($row) => ['bucket' => $row['bucket'], 'amount' => round((float) $row['amount'], 2)]),
            'reconciliation' => [
                'invoice_amount' => round((float) $activeInvoiceAmount, 2),
                'invoice_ledger_revenue' => round((float) $invoiceLedgerRevenue, 2),
                'difference' => round((float) ($invoiceLedgerRevenue - $activeInvoiceAmount), 2),
                'manual_revenue' => round((float) $manualRevenue, 2),
                'is_reconciled' => abs($invoiceLedgerRevenue - $activeInvoiceAmount) < 0.01,
            ],
            'invoices' => $invoiceRows->take(20)->values(),
            'records' => $records->take(20)->values(),
            'period' => $this->periodMetadata($start, $end),
        ];
    }

    public function it(?CarbonInterface $period = null): array
    {
        $end = $this->reportingEnd($period);
        $start = $end->startOfMonth()->subMonths(11);
        $services = ItService::query()
            ->where(fn ($q) => $q->whereNull('monitoring_started_at')->orWhere('monitoring_started_at', '<=', $end))
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhere('archived_at', '>', $start))
            ->orderBy('name')
            ->get();
        $incidents = ItIncident::with('service')
            ->where('started_at', '<=', $end)
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $start))
            ->orderByDesc('started_at')
            ->get();
        $qualityRuns = DataQualityRun::query()
            ->whereBetween('assessed_at', [$start, $end])
            ->orderByDesc('assessed_at')
            ->get();

        $serviceRows = $services->map(function (ItService $service) use ($incidents, $start, $end) {
            $serviceIncidents = $incidents->where('it_service_id', $service->id);
            [$serviceStart, $serviceEnd] = $this->serviceAvailabilityWindow($service, $start, $end);
            $downtimeMinutes = $this->uniqueDowntimeMinutes($serviceIncidents, $serviceStart, $serviceEnd);
            $possibleMinutes = max(1, $serviceStart->diffInMinutes($serviceEnd));
            $uptime = max(0, 100 - ($downtimeMinutes / $possibleMinutes * 100));
            $open = $serviceIncidents->filter(fn ($incident) => $incident->started_at->lte($serviceEnd)
                && (! $incident->resolved_at || $incident->resolved_at->gt($serviceEnd)));

            return [
                'id' => $service->id,
                'name' => $service->name,
                'owner' => $service->owner,
                'target_uptime' => (float) $service->target_uptime,
                'status' => $service->status,
                'uptime' => round($uptime, 3),
                'downtime_hours' => round($downtimeMinutes / 60, 2),
                'incident_count' => $serviceIncidents->count(),
                'open_incidents' => $open->count(),
                'meets_target' => $uptime >= (float) $service->target_uptime,
            ];
        });

        $possibleMinutes = $services->sum(function (ItService $service) use ($start, $end): float {
            [$serviceStart, $serviceEnd] = $this->serviceAvailabilityWindow($service, $start, $end);

            return max(0, $serviceStart->diffInMinutes($serviceEnd));
        });
        $downtimeMinutes = $services->sum(function (ItService $service) use ($incidents, $start, $end): float {
            [$serviceStart, $serviceEnd] = $this->serviceAvailabilityWindow($service, $start, $end);

            return $this->uniqueDowntimeMinutes($incidents->where('it_service_id', $service->id), $serviceStart, $serviceEnd);
        });
        $uptime = $possibleMinutes > 0 ? max(0, 100 - ($downtimeMinutes / $possibleMinutes * 100)) : 0.0;
        $resolved = $incidents->filter(fn ($incident) => $incident->resolved_at
            && $incident->resolved_at->betweenIncluded($start, $end));
        $openAtEnd = $incidents->filter(fn ($incident) => $incident->started_at->lte($end)
            && (! $incident->resolved_at || $incident->resolved_at->gt($end)));
        $qualityTotals = $this->qualityTotals($qualityRuns);

        return [
            'summary' => [
                'uptime' => round($uptime, 3),
                'open_incidents' => $openAtEnd->count(),
                'critical_incidents' => $incidents->filter(fn ($incident) => $incident->severity === 'critical'
                    && $incident->started_at->betweenIncluded($start, $end))->count(),
                'mttr_hours' => $resolved->count()
                    ? round($resolved->avg(fn ($incident) => $incident->started_at->diffInMinutes($incident->resolved_at)) / 60, 2)
                    : 0,
                'downtime_hours' => round($downtimeMinutes / 60, 2),
                'data_quality' => $qualityTotals['score'],
                'quality_records' => $qualityTotals['total_records'],
            ],
            'monthly' => $this->incidentMonthlySeries($incidents, $services, $qualityRuns, $end),
            'severity_breakdown' => collect(['low', 'medium', 'high', 'critical'])->map(fn ($severity) => [
                'severity' => $severity,
                'count' => $incidents->filter(fn ($incident) => $incident->severity === $severity
                    && $incident->started_at->betweenIncluded($start, $end))->count(),
            ])->values(),
            'status_breakdown' => collect(['open', 'investigating', 'resolved'])->map(fn ($status) => [
                'status' => $status,
                'count' => $incidents->filter(fn ($incident) => $this->incidentStatusAsOf($incident, $end) === $status)->count(),
            ])->values(),
            'services' => $serviceRows->values(),
            'incidents' => $incidents->take(20)->map(fn (ItIncident $incident) => $this->incidentAsOf($incident, $end))->values(),
            'data_quality_runs' => $qualityRuns->map(fn (DataQualityRun $run) => [
                'id' => $run->id,
                'reference' => $run->reference,
                'dataset_name' => $run->dataset_name,
                'source_system' => $run->source_system,
                'assessed_at' => $run->assessed_at?->toIso8601String(),
                'total_records' => $run->total_records,
                'valid_records' => $run->valid_records,
                'missing_required_records' => $run->missing_required_records,
                'duplicate_records' => $run->duplicate_records,
                'freshness_failures' => $run->freshness_failures,
                'quality_score' => $run->qualityScore(),
                'notes' => $run->notes,
            ])->take(20)->values(),
            'period' => $this->periodMetadata($start, $end),
        ];
    }

    private function formatKpi(KpiDefinition $definition, CarbonInterface $end): array
    {
        if ($definition->isSystemDerived()) {
            return $this->formatSystemDerivedKpi($definition, $end);
        }

        $latest = $definition->measurements->last();
        $config = $definition->configurationFor($end);
        $actual = (float) ($latest?->actual ?? 0);
        $target = (float) ($config?->target ?? $definition->target);
        $warningThreshold = $config?->warning_threshold ?? $definition->warning_threshold;
        $weight = (float) ($config?->weight ?? $definition->weight);
        $score = $this->score($actual, $target, $definition->direction);

        return [
            'id' => $definition->id,
            'code' => $definition->code,
            'name' => $definition->name,
            'description' => $definition->description,
            'unit' => $definition->unit,
            'direction' => $definition->direction,
            'weight' => $weight,
            'target' => $target,
            'warning_threshold' => $warningThreshold,
            'actual' => $actual,
            'score' => round($score, 1),
            'status' => $this->statusForKpi($actual, $target, $warningThreshold, $definition->direction, $score),
            'period' => $latest?->period?->format('Y-m-d'),
            'data_source' => $definition->data_source,
            'source_type' => 'manual_measurement',
            'is_system_derived' => false,
            'owner_name' => $config?->owner_name,
            'effective_from' => $config?->effective_from?->format('Y-m-d'),
            'department' => $definition->department,
            'trend' => $definition->measurements->take(-12)->map(fn ($measurement) => [
                'period' => $measurement->period->format('M y'),
                'actual' => $measurement->actual,
                'target' => $measurement->target_snapshot,
            ])->values(),
        ];
    }

    private function formatSystemDerivedKpi(KpiDefinition $definition, CarbonInterface $end): array
    {
        $series = match (true) {
            str_starts_with($definition->code, 'CERT-') => $this->certificationKpiSeries($definition, $end),
            str_starts_with($definition->code, 'FIN-') => $this->financeKpiSeries($definition, $end),
            str_starts_with($definition->code, 'IT-') => $this->itKpiSeries($definition, $end),
            default => collect(),
        };
        $latest = $series->last();
        $config = $definition->configurationFor($end);
        $actual = (float) ($latest['actual'] ?? 0);
        $target = (float) ($config?->target ?? $definition->target);
        $warningThreshold = $config?->warning_threshold ?? $definition->warning_threshold;
        $weight = (float) ($config?->weight ?? $definition->weight);
        $score = $this->score($actual, $target, $definition->direction);

        return [
            'id' => $definition->id,
            'code' => $definition->code,
            'name' => $definition->name,
            'description' => $definition->description,
            'unit' => $definition->unit,
            'direction' => $definition->direction,
            'weight' => $weight,
            'target' => $target,
            'warning_threshold' => $warningThreshold,
            'actual' => $actual,
            'score' => round($score, 1),
            'status' => $this->statusForKpi($actual, $target, $warningThreshold, $definition->direction, $score),
            'period' => $latest['period_date'] ?? null,
            'data_source' => match (true) {
                str_starts_with($definition->code, 'CERT-') => 'Ledger operasional sertifikasi (dihitung otomatis)',
                str_starts_with($definition->code, 'FIN-') => 'Ledger transaksi keuangan (dihitung otomatis)',
                $definition->code === 'IT-DATA' => 'Ledger audit kualitas data (dihitung otomatis)',
                str_starts_with($definition->code, 'IT-') => 'Log insiden dan layanan TI (dihitung otomatis)',
                default => $definition->data_source,
            },
            'source_type' => 'system_derived',
            'is_system_derived' => true,
            'sample_size' => $latest['sample_size'] ?? 0,
            'owner_name' => $config?->owner_name,
            'effective_from' => $config?->effective_from?->format('Y-m-d'),
            'department' => $definition->department,
            'trend' => $series->map(function ($row) use ($definition, $target) {
                $rowConfig = $definition->configurationFor($row['period_date'] ?? null);

                return [
                    'period' => $row['period'],
                    'actual' => $row['actual'],
                    'target' => (float) ($rowConfig?->target ?? $target),
                ];
            })->values(),
        ];
    }

    private function certificationKpiSeries(KpiDefinition $definition, CarbonInterface $end): Collection
    {
        $months = collect(range(11, 0))->map(fn ($offset) => CarbonImmutable::instance($end)->startOfMonth()->subMonths($offset));
        $windowStart = $months->first()->startOfMonth();
        $windowEnd = CarbonImmutable::instance($end);

        $historicalIds = $this->certificationAuditCandidateIds($windowStart, $windowEnd, [
            'assessment_date', 'decision_at', 'certificate_due_at',
        ]);
        $batches = CertificationBatch::with('issuances')
            ->where(function ($query) use ($windowStart, $windowEnd, $historicalIds) {
                $query->whereBetween('assessment_date', [$windowStart, $windowEnd])
                    ->orWhereBetween('decision_at', [$windowStart, $windowEnd])
                    ->orWhereBetween('certificate_due_at', [$windowStart, $windowEnd]);
                if ($historicalIds->isNotEmpty()) {
                    $query->orWhereIn('id', $historicalIds->all());
                }
            })
            ->get();
        $audits = $this->certificationAudits($batches->pluck('id'));

        return $months->map(function (CarbonImmutable $month) use ($definition, $batches, $audits, $end) {
            $monthKey = $month->format('Y-m');
            $cutoff = $month->isSameMonth($end) ? CarbonImmutable::instance($end) : $month->endOfMonth();
            $rows = $batches->map(fn (CertificationBatch $batch) => $this->certificationBatchAsOf(
                $batch,
                $cutoff,
                collect($audits->get($batch->id, [])),
            ));
            $actual = 0.0;
            $sampleSize = 0;

            if ($definition->code === 'CERT-VOLUME') {
                $matches = $rows->filter(fn (array $row) => ! empty($row['assessment_date'])
                    && CarbonImmutable::parse($row['assessment_date'])->format('Y-m') === $monthKey);
                $actual = (float) $matches->sum('total_assesi');
                $sampleSize = $matches->count();
            } elseif ($definition->code === 'CERT-PASS') {
                $matches = $rows->filter(fn (array $row) => ! empty($row['decision_at'])
                    && CarbonImmutable::parse($row['decision_at'])->format('Y-m') === $monthKey);
                $sampleSize = (int) $matches->sum(fn (array $row) => (int) $row['passed'] + (int) $row['failed']);
                $actual = $sampleSize > 0 ? round($matches->sum('passed') / $sampleSize * 100, 2) : 0.0;
            } elseif ($definition->code === 'CERT-SLA') {
                $matches = $rows->filter(fn (array $row) => ! empty($row['certificate_due_at'])
                    && CarbonImmutable::parse($row['certificate_due_at'])->format('Y-m') === $monthKey);
                $sampleSize = (int) $matches->sum('passed');
                $onTime = (int) $matches->sum(fn (array $row) => min(
                    (int) $row['passed'],
                    collect($row['issuances'] ?? [])->filter(fn (array $issuance) => ! empty($issuance['issued_at'])
                        && CarbonImmutable::parse($issuance['issued_at'])->lte(CarbonImmutable::parse($row['certificate_due_at'])))->sum('issued_count')
                ));
                $actual = $sampleSize > 0 ? round($onTime / $sampleSize * 100, 2) : 0.0;
            }

            return [
                'period' => $month->format('M y'),
                'period_date' => $month->toDateString(),
                'actual' => $actual,
                'sample_size' => $sampleSize,
            ];
        });
    }

    private function financeKpiSeries(KpiDefinition $definition, CarbonInterface $end): Collection
    {
        $months = collect(range(11, 0))->map(fn ($offset) => CarbonImmutable::instance($end)->startOfMonth()->subMonths($offset));
        $windowStart = $months->first()->startOfMonth();
        $windowEnd = CarbonImmutable::instance($end);
        $records = FinancialRecord::whereBetween('recorded_on', [$windowStart, $windowEnd])->get();

        return $months->map(function (CarbonImmutable $month) use ($definition, $records) {
            $matches = $records->filter(fn ($record) => $record->recorded_on?->format('Y-m') === $month->format('Y-m'));
            $revenue = $this->netLedgerAmount($matches, 'revenue');
            $expense = $this->netLedgerAmount($matches, 'expense');
            $budget = $this->netLedgerAmount($matches, 'budget');
            $actual = match ($definition->code) {
                'FIN-REV' => round($revenue / 1_000_000, 2),
                'FIN-MARGIN' => $revenue > 0 ? round(($revenue - $expense) / $revenue * 100, 2) : 0.0,
                'FIN-BUDGET' => $budget > 0 ? round(abs($expense - $budget) / $budget * 100, 2) : 0.0,
                default => 0.0,
            };

            return [
                'period' => $month->format('M y'),
                'period_date' => $month->toDateString(),
                'actual' => $actual,
                'sample_size' => $matches->count(),
            ];
        });
    }

    private function itKpiSeries(KpiDefinition $definition, CarbonInterface $end): Collection
    {
        $months = collect(range(11, 0))->map(fn ($offset) => CarbonImmutable::instance($end)->startOfMonth()->subMonths($offset));
        $windowStart = $months->first()->startOfMonth();
        $services = ItService::query()
            ->where(fn ($q) => $q->whereNull('monitoring_started_at')->orWhere('monitoring_started_at', '<=', $end))
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhere('archived_at', '>', $windowStart))
            ->get();
        $incidents = ItIncident::query()
            ->where('started_at', '<=', $end)
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $windowStart))
            ->get();
        $qualityRuns = DataQualityRun::query()->whereBetween('assessed_at', [$windowStart, $end])->get();

        return $months->map(function (CarbonImmutable $month) use ($definition, $end, $services, $incidents, $qualityRuns) {
            $monthStart = $month->startOfMonth();
            $monthEnd = $month->isSameMonth($end) ? CarbonImmutable::instance($end) : $month->endOfMonth();
            $actual = 0.0;
            $sampleSize = 0;

            if ($definition->code === 'IT-UPTIME') {
                $activeServices = $services->filter(fn (ItService $service) => $this->serviceOverlapsWindow($service, $monthStart, $monthEnd));
                $possibleMinutes = $activeServices->sum(function (ItService $service) use ($monthStart, $monthEnd): float {
                    [$serviceStart, $serviceEnd] = $this->serviceAvailabilityWindow($service, $monthStart, $monthEnd);

                    return max(0, $serviceStart->diffInMinutes($serviceEnd));
                });
                $downtimeMinutes = $activeServices->sum(function (ItService $service) use ($incidents, $monthStart, $monthEnd): float {
                    [$serviceStart, $serviceEnd] = $this->serviceAvailabilityWindow($service, $monthStart, $monthEnd);

                    return $this->uniqueDowntimeMinutes($incidents->where('it_service_id', $service->id), $serviceStart, $serviceEnd);
                });
                $actual = $possibleMinutes > 0 ? round(max(0, 100 - ($downtimeMinutes / $possibleMinutes * 100)), 3) : 0.0;
                $sampleSize = $activeServices->count();
            } elseif ($definition->code === 'IT-MTTR') {
                $matches = $incidents->filter(fn ($incident) => $incident->resolved_at
                    && $incident->resolved_at->betweenIncluded($monthStart, $monthEnd));
                $sampleSize = $matches->count();
                $actual = $sampleSize > 0
                    ? round($matches->avg(fn ($incident) => $incident->started_at->diffInMinutes($incident->resolved_at)) / 60, 2)
                    : 0.0;
            } elseif ($definition->code === 'IT-DATA') {
                $matches = $qualityRuns->filter(fn ($run) => $run->assessed_at->betweenIncluded($monthStart, $monthEnd));
                $totals = $this->qualityTotals($matches);
                $actual = $totals['score'];
                $sampleSize = $totals['total_records'];
            }

            return [
                'period' => $month->format('M y'),
                'period_date' => $monthStart->toDateString(),
                'actual' => $actual,
                'sample_size' => $sampleSize,
            ];
        });
    }

    private function qualityTotals(Collection $runs): array
    {
        $total = (int) $runs->sum('total_records');
        $valid = (int) $runs->sum('valid_records');

        return [
            'total_records' => $total,
            'valid_records' => $valid,
            'score' => $total > 0 ? round($valid / $total * 100, 2) : 0.0,
        ];
    }

    private function serviceOverlapsWindow(ItService $service, CarbonInterface $start, CarbonInterface $end): bool
    {
        $monitoringStart = $service->monitoring_started_at ? CarbonImmutable::instance($service->monitoring_started_at) : CarbonImmutable::instance($start);
        $archivedAt = $service->archived_at ? CarbonImmutable::instance($service->archived_at) : null;

        return $monitoringStart->lt($end) && (! $archivedAt || $archivedAt->gt($start));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function serviceAvailabilityWindow(ItService $service, CarbonInterface $start, CarbonInterface $end): array
    {
        $windowStart = CarbonImmutable::instance($start);
        $windowEnd = CarbonImmutable::instance($end);
        $monitoringStart = $service->monitoring_started_at ? CarbonImmutable::instance($service->monitoring_started_at) : $windowStart;
        $archivedAt = $service->archived_at ? CarbonImmutable::instance($service->archived_at) : $windowEnd;
        $serviceStart = $monitoringStart->gt($windowStart) ? $monitoringStart : $windowStart;
        $serviceEnd = $archivedAt->lt($windowEnd) ? $archivedAt : $windowEnd;

        return [$serviceStart, $serviceEnd->lt($serviceStart) ? $serviceStart : $serviceEnd];
    }

    private function uniqueDowntimeMinutes(Collection $incidents, CarbonInterface $start, CarbonInterface $end): float
    {
        $startTs = CarbonImmutable::instance($start)->timestamp;
        $endTs = CarbonImmutable::instance($end)->timestamp;
        if ($endTs <= $startTs) {
            return 0.0;
        }

        $intervals = $incidents->map(function (ItIncident $incident) use ($startTs, $endTs) {
            $incidentStart = max($startTs, $incident->started_at->timestamp);
            $incidentEnd = min($endTs, ($incident->resolved_at ?? CarbonImmutable::createFromTimestamp($endTs))->timestamp);

            return $incidentEnd > $incidentStart ? [$incidentStart, $incidentEnd] : null;
        })->filter()->sortBy(fn ($interval) => $interval[0])->values();

        if ($intervals->isEmpty()) {
            return 0.0;
        }

        $totalSeconds = 0;
        [$currentStart, $currentEnd] = $intervals->first();
        foreach ($intervals->slice(1) as [$nextStart, $nextEnd]) {
            if ($nextStart <= $currentEnd) {
                $currentEnd = max($currentEnd, $nextEnd);

                continue;
            }

            $totalSeconds += $currentEnd - $currentStart;
            $currentStart = $nextStart;
            $currentEnd = $nextEnd;
        }
        $totalSeconds += $currentEnd - $currentStart;

        return $totalSeconds / 60;
    }

    private function netLedgerAmount(Collection $records, string $type): float
    {
        return (float) $records->where('type', $type)->sum(fn ($record) => $this->signedAmount($record));
    }

    private function signedAmount(FinancialRecord $record): float
    {
        return ($record->entry_kind ?? 'normal') === 'reversal' ? -1 * (float) $record->amount : (float) $record->amount;
    }

    private function invoiceAsOf(FinanceInvoice $invoice, CarbonInterface $end): array
    {
        $voidedAsOf = $invoice->voided_at && $invoice->voided_at->lte($end);
        $activePayments = $invoice->payments->filter(fn ($payment) => $payment->paid_on->lte($end)
            && (! $payment->reversed_at || $payment->reversed_at->gt($end)));
        $paid = (float) $activePayments->sum('amount');
        $outstanding = $voidedAsOf ? 0.0 : max(0, (float) $invoice->amount - $paid);
        $daysOverdue = $outstanding > 0.005 && $invoice->due_on->lt($end)
            ? $invoice->due_on->startOfDay()->diffInDays(CarbonImmutable::instance($end)->startOfDay())
            : 0;
        $status = $voidedAsOf ? 'void' : ($outstanding <= 0.005 ? 'paid' : ($paid > 0.005 ? 'partially_paid' : 'issued'));

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'issued_on' => $invoice->issued_on->toDateString(),
            'due_on' => $invoice->due_on->toDateString(),
            'customer_name' => $invoice->customer_name,
            'category' => $invoice->category,
            'department_code' => $invoice->department_code,
            'amount' => (float) $invoice->amount,
            'paid' => round($paid, 2),
            'outstanding' => round($outstanding, 2),
            'days_overdue' => $daysOverdue,
            'status' => $status,
            'description' => $invoice->description,
            'is_active_as_of' => ! $voidedAsOf,
            'payments' => $invoice->payments
                ->filter(fn ($payment) => $payment->paid_on->lte($end))
                ->map(fn ($payment) => [
                    'id' => $payment->id,
                    'paid_on' => $payment->paid_on->toDateString(),
                    'amount' => (float) $payment->amount,
                    'reference' => $payment->reference,
                    'reversed_at' => $payment->reversed_at && $payment->reversed_at->lte($end)
                        ? $payment->reversed_at->toIso8601String()
                        : null,
                ])->values(),
        ];
    }

    private function certificationBatchAsOf(CertificationBatch $batch, CarbonInterface $end, ?Collection $logs = null): array
    {
        $state = $batch->only([
            'status', 'passed', 'failed', 'pending', 'assessment_completed_at', 'decision_at',
            'certificate_due_at', 'completed_at',
        ]);
        $logs ??= collect();
        foreach ($logs
            ->filter(fn ($log) => $log->created_at && $log->created_at->gt($end))
            ->sortByDesc(fn ($log) => sprintf('%020d-%020d', $log->created_at->getTimestamp(), $log->id)) as $log) {
            $before = data_get($log->changes ?? [], 'before');
            if (! is_array($before)) {
                continue;
            }
            foreach (array_keys($state) as $field) {
                if (array_key_exists($field, $before)) {
                    $state[$field] = $before[$field];
                }
            }
        }

        $decisionAt = ! empty($state['decision_at']) ? CarbonImmutable::parse($state['decision_at']) : null;
        $completedAt = ! empty($state['completed_at']) ? CarbonImmutable::parse($state['completed_at']) : null;
        $assessmentCompletedAt = ! empty($state['assessment_completed_at']) ? CarbonImmutable::parse($state['assessment_completed_at']) : null;
        $certificateDueAt = ! empty($state['certificate_due_at']) ? CarbonImmutable::parse($state['certificate_due_at']) : null;
        $decisionVisible = $decisionAt && $decisionAt->lte($end);
        $completedVisible = $completedAt && $completedAt->lte($end);
        $assessmentCompletedVisible = $assessmentCompletedAt && $assessmentCompletedAt->lte($end);
        $visibleIssuances = $batch->issuances->filter(fn ($issuance) => $issuance->issued_at->lte($end));
        $passed = $decisionVisible ? (int) ($state['passed'] ?? 0) : 0;
        $failed = $decisionVisible ? (int) ($state['failed'] ?? 0) : 0;
        $pending = max(0, (int) $batch->total_assesi - $passed - $failed);
        $issued = (int) $visibleIssuances->sum('issued_count');
        $issuedOnTime = $decisionVisible && $certificateDueAt
            ? (int) $visibleIssuances->filter(fn ($issuance) => $issuance->issued_at->lte($certificateDueAt))->sum('issued_count')
            : 0;

        $rolledStatus = (string) ($state['status'] ?? $batch->status);
        $statusAsOf = match (true) {
            $completedVisible => 'completed',
            $decisionVisible => 'decision',
            $assessmentCompletedVisible => 'assessment',
            in_array($rolledStatus, ['planned', 'document_review', 'assessment'], true) => $rolledStatus,
            default => 'assessment',
        };

        $row = $batch->toArray();
        $row['status'] = $statusAsOf;
        $row['status_as_of'] = $statusAsOf;
        $row['passed'] = $passed;
        $row['failed'] = $failed;
        $row['pending'] = $pending;
        $row['assessment_date'] = $batch->assessment_date?->toDateString();
        $row['assessment_completed_at'] = $assessmentCompletedVisible ? $assessmentCompletedAt->toIso8601String() : null;
        $row['decision_at'] = $decisionVisible ? $decisionAt->toIso8601String() : null;
        $row['certificate_due_at'] = $decisionVisible && $certificateDueAt ? $certificateDueAt->toIso8601String() : null;
        $row['completed_at'] = $completedVisible ? $completedAt->toIso8601String() : null;
        $row['certificates_issued'] = $issued;
        $row['issued_on_time'] = min($issuedOnTime, $passed);
        $row['issuances'] = $visibleIssuances->map(fn ($issuance) => $issuance->toArray())->values()->all();

        return $row;
    }

    /**
     * Include batches whose current lifecycle dates have been corrected out of the
     * requested historical window. Without this candidate expansion the row can be
     * discarded by SQL before certificationBatchAsOf() gets a chance to roll the
     * change back from AuditLog.before.
     */
    private function certificationAuditCandidateIds(?CarbonInterface $start, CarbonInterface $end, array $fields): Collection
    {
        return AuditLog::query()
            ->where('entity_type', CertificationBatch::class)
            ->whereNotNull('entity_id')
            ->where('created_at', '>', $end)
            ->orderBy('entity_id')
            ->get(['entity_id', 'changes'])
            ->filter(function (AuditLog $log) use ($start, $end, $fields): bool {
                $before = data_get($log->changes ?? [], 'before');
                if (! is_array($before)) {
                    return false;
                }

                foreach ($fields as $field) {
                    $value = $before[$field] ?? null;
                    if ($value === null || $value === '') {
                        continue;
                    }

                    try {
                        $date = CarbonImmutable::parse($value);
                    } catch (\Throwable) {
                        continue;
                    }

                    if ($date->lte($end) && ($start === null || $date->gte($start))) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function certificationAudits(Collection $batchIds): Collection
    {
        if ($batchIds->isEmpty()) {
            return collect();
        }

        return AuditLog::query()
            ->where('entity_type', CertificationBatch::class)
            ->whereIn('entity_id', $batchIds->all())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('entity_id');
    }

    private function incidentAsOf(ItIncident $incident, CarbonInterface $end): array
    {
        $statusAsOf = $this->incidentStatusAsOf($incident, $end);
        $resolvedVisible = $incident->resolved_at && $incident->resolved_at->lte($end);
        $acknowledgedVisible = $incident->acknowledged_at && $incident->acknowledged_at->lte($end);
        $row = $incident->toArray();

        $row['status'] = $statusAsOf;
        $row['status_as_of'] = $statusAsOf;
        $row['acknowledged_at'] = $acknowledgedVisible ? $incident->acknowledged_at->toIso8601String() : null;
        $row['resolved_at'] = $resolvedVisible ? $incident->resolved_at->toIso8601String() : null;
        $row['resolved_by'] = $resolvedVisible ? $incident->resolved_by : null;
        $row['resolution_summary'] = $resolvedVisible ? $incident->resolution_summary : null;
        $row['root_cause'] = $resolvedVisible ? $incident->root_cause : null;

        return $row;
    }

    private function score(float $actual, float $target, string $direction): float
    {
        return $direction === 'lower'
            ? ($actual <= 0 ? 120 : min(120, $target / $actual * 100))
            : ($target <= 0 ? 0 : min(120, $actual / $target * 100));
    }

    private function statusForKpi(float $actual, float $target, ?float $warningThreshold, string $direction, float $score): string
    {
        if ($warningThreshold === null) {
            return $this->statusFromScore($score);
        }

        if ($direction === 'lower') {
            return $actual <= $target ? 'on_track' : ($actual <= $warningThreshold ? 'watch' : 'critical');
        }

        return $actual >= $target ? 'on_track' : ($actual >= $warningThreshold ? 'watch' : 'critical');
    }

    private function statusFromScore(float $score): string
    {
        return $score >= 100 ? 'on_track' : ($score >= 90 ? 'watch' : 'critical');
    }

    private function lastUpdatedAt(Collection $definitions, ?string $departmentCode, CarbonInterface $end): ?string
    {
        $cutoff = CarbonImmutable::instance($end);
        $candidates = collect();
        $pushVisible = function (mixed $value) use ($candidates, $cutoff): void {
            if (! $value) {
                return;
            }
            $timestamp = CarbonImmutable::parse($value);
            if ($timestamp->lte($cutoff)) {
                $candidates->push($timestamp);
            }
        };

        $definitions->flatMap->measurements->each(fn ($row) => $pushVisible($row->updated_at));
        $definitions->flatMap->configurations->each(fn ($row) => $pushVisible($row->updated_at));

        if ($departmentCode === null || $departmentCode === 'certification') {
            $pushVisible(CertificationBatch::where('assessment_date', '<=', $cutoff)->max('assessment_date'));
            $pushVisible(CertificationBatch::whereNotNull('assessment_completed_at')->where('assessment_completed_at', '<=', $cutoff)->max('assessment_completed_at'));
            $pushVisible(CertificationBatch::whereNotNull('decision_at')->where('decision_at', '<=', $cutoff)->max('decision_at'));
            $pushVisible(CertificationBatch::whereNotNull('completed_at')->where('completed_at', '<=', $cutoff)->max('completed_at'));
            $pushVisible(CertificateIssuance::where('issued_at', '<=', $cutoff)->max('issued_at'));
        }
        if ($departmentCode === null || $departmentCode === 'finance') {
            $pushVisible(FinancialRecord::where('recorded_on', '<=', $cutoff->toDateString())->max('recorded_on'));
            $pushVisible(FinanceInvoice::where('issued_on', '<=', $cutoff->toDateString())->max('issued_on'));
            $pushVisible(FinanceInvoice::whereNotNull('voided_at')->where('voided_at', '<=', $cutoff)->max('voided_at'));
            $pushVisible(FinancePayment::where('paid_on', '<=', $cutoff->toDateString())->max('paid_on'));
            $pushVisible(FinancePayment::whereNotNull('reversed_at')->where('reversed_at', '<=', $cutoff)->max('reversed_at'));
        }
        if ($departmentCode === null || $departmentCode === 'it') {
            $pushVisible(ItIncident::where('started_at', '<=', $cutoff)->max('started_at'));
            $pushVisible(ItIncident::whereNotNull('acknowledged_at')->where('acknowledged_at', '<=', $cutoff)->max('acknowledged_at'));
            $pushVisible(ItIncident::whereNotNull('resolved_at')->where('resolved_at', '<=', $cutoff)->max('resolved_at'));
            $pushVisible(ItService::whereNotNull('monitoring_started_at')->where('monitoring_started_at', '<=', $cutoff)->max('monitoring_started_at'));
            $pushVisible(DataQualityRun::where('assessed_at', '<=', $cutoff)->max('assessed_at'));
        }

        return $candidates->sortDesc()->first()?->toIso8601String();
    }

    private function actionStatusAsOf(ActionItem $action, CarbonInterface $end): string
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

    private function signals(Collection $critical, Collection $watch): Collection
    {
        return $critical->take(3)->map(fn ($kpi) => [
            'severity' => 'critical',
            'title' => $kpi['name'].' di luar ambang',
            'detail' => "Realisasi {$kpi['actual']} {$kpi['unit']} terhadap target {$kpi['target']} {$kpi['unit']}.",
            'department' => $kpi['department']['name'],
        ])->concat($watch->take(2)->map(fn ($kpi) => [
            'severity' => 'watch',
            'title' => $kpi['name'].' perlu dipantau',
            'detail' => "Skor pencapaian {$kpi['score']}%. Tinjau akar masalah sebelum periode berikutnya.",
            'department' => $kpi['department']['name'],
        ]))->values();
    }

    private function monthlySeries(Collection $items, string $dateField, array $aggregates, CarbonInterface $end): Collection
    {
        $months = collect(range(11, 0))->map(fn ($offset) => $end->startOfMonth()->subMonths($offset));

        return $months->map(function (CarbonInterface $month) use ($items, $dateField, $aggregates) {
            $matches = $items->filter(fn ($item) => $item->{$dateField}->format('Y-m') === $month->format('Y-m'));
            $row = ['period' => $month->format('M y')];
            foreach ($aggregates as $key => $callback) {
                $row[$key] = $callback($matches);
            }

            return $row;
        });
    }

    private function incidentStatusAsOf(ItIncident $incident, CarbonInterface $end): string
    {
        if ($incident->resolved_at && $incident->resolved_at->lte($end)) {
            return 'resolved';
        }
        if ($incident->acknowledged_at && $incident->acknowledged_at->lte($end)) {
            return 'investigating';
        }

        return 'open';
    }

    private function incidentMonthlySeries(Collection $incidents, Collection $services, Collection $qualityRuns, CarbonInterface $end): Collection
    {
        return collect(range(11, 0))->map(fn ($offset) => CarbonImmutable::instance($end)->startOfMonth()->subMonths($offset))
            ->map(function (CarbonImmutable $month) use ($incidents, $services, $qualityRuns, $end) {
                $monthStart = $month->startOfMonth();
                $monthEnd = $month->isSameMonth($end) ? CarbonImmutable::instance($end) : $month->endOfMonth();
                $started = $incidents->filter(fn ($incident) => $incident->started_at->betweenIncluded($monthStart, $monthEnd));
                $resolved = $incidents->filter(fn ($incident) => $incident->resolved_at
                    && $incident->resolved_at->betweenIncluded($monthStart, $monthEnd));
                $activeServices = $services->filter(fn (ItService $service) => $this->serviceOverlapsWindow($service, $monthStart, $monthEnd));
                $downtimeMinutes = $activeServices->sum(function (ItService $service) use ($incidents, $monthStart, $monthEnd): float {
                    [$serviceStart, $serviceEnd] = $this->serviceAvailabilityWindow($service, $monthStart, $monthEnd);

                    return $this->uniqueDowntimeMinutes($incidents->where('it_service_id', $service->id), $serviceStart, $serviceEnd);
                });
                $possibleMinutes = $activeServices->sum(function (ItService $service) use ($monthStart, $monthEnd): float {
                    [$serviceStart, $serviceEnd] = $this->serviceAvailabilityWindow($service, $monthStart, $monthEnd);

                    return max(0, $serviceStart->diffInMinutes($serviceEnd));
                });
                $quality = $this->qualityTotals($qualityRuns->filter(fn ($run) => $run->assessed_at->betweenIncluded($monthStart, $monthEnd)));

                return [
                    'period' => $month->format('M y'),
                    'incidents' => $started->count(),
                    'downtime_hours' => round($downtimeMinutes / 60, 2),
                    'uptime' => $possibleMinutes > 0 ? round(max(0, 100 - ($downtimeMinutes / $possibleMinutes * 100)), 3) : 0.0,
                    'mttr_hours' => $resolved->count()
                        ? round($resolved->avg(fn ($incident) => $incident->started_at->diffInMinutes($incident->resolved_at)) / 60, 2)
                        : 0.0,
                    'data_quality' => $quality['score'],
                ];
            });
    }

    private function reportingEnd(?CarbonInterface $period): CarbonImmutable
    {
        $requested = CarbonImmutable::instance($period ?? now())->endOfMonth();
        $now = CarbonImmutable::instance(now());

        return $requested->isSameMonth($now) || $requested->gt($now)
            ? $now
            : $requested;
    }

    private function periodMetadata(CarbonInterface $start, CarbonInterface $end): array
    {
        return [
            'as_of' => $end->toDateString(),
            'window_start' => $start->toDateString(),
            'window_end' => $end->toDateString(),
        ];
    }
}
