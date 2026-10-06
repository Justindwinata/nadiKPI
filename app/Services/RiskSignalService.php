<?php

namespace App\Services;

use App\Models\CertificateIssuance;
use App\Models\CertificationBatch;
use App\Models\ComplianceFinding;
use App\Models\ComplianceObligation;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\FinanceInvoice;
use App\Models\ItIncident;
use App\Models\RiskSignal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RiskSignalService
{
    private const MANAGED_RULES = [
        'KPI_EXCEPTION', 'FINDING_OVERDUE', 'CAPA_OVERDUE', 'RECEIVABLE_OVERDUE',
        'CERTIFICATE_BACKLOG', 'IT_INCIDENT', 'OBLIGATION_EXPIRY',
    ];

    public function __construct(private readonly KpiAnalyticsService $analytics) {}

    public function sync(?CarbonInterface $asOf = null): Collection
    {
        $asOf = $asOf ? $asOf->copy() : now();
        $syncStartedAt = $asOf->copy();
        $departments = Department::query()->get()->keyBy('code');

        $candidates = collect()
            ->merge($this->kpiCandidates($asOf))
            ->merge($this->findingCandidates($asOf, $departments))
            ->merge($this->capaCandidates($asOf, $departments))
            ->merge($this->receivableCandidates($asOf, $departments))
            ->merge($this->certificateCandidates($asOf, $departments))
            ->merge($this->incidentCandidates($asOf, $departments))
            ->merge($this->obligationCandidates($asOf, $departments));

        foreach ($candidates as $candidate) {
            $signal = RiskSignal::query()->firstOrCreate(
                ['fingerprint' => $candidate['fingerprint']],
                collect($candidate)->except('fingerprint')->all() + [
                    'status' => 'open',
                    'detected_at' => $syncStartedAt,
                    'last_observed_at' => $syncStartedAt,
                ]
            );
            $isNew = $signal->wasRecentlyCreated;
            $wasResolved = in_array($signal->status, ['resolved', 'dismissed'], true);
            $existingDueAt = $signal->due_at?->copy();

            $signal->fill($candidate + ['last_observed_at' => $syncStartedAt]);
            if (! $isNew && ! $wasResolved && $existingDueAt) {
                $signal->due_at = $existingDueAt;
            }
            if ($isNew || $wasResolved) {
                $signal->status = 'open';
                $signal->detected_at = $syncStartedAt;
                $signal->resolved_at = null;
                $signal->resolved_by = null;
                $signal->resolution_note = null;
                if ($wasResolved) {
                    $signal->acknowledged_at = null;
                    $signal->acknowledged_by = null;
                    $signal->escalation_level = 0;
                    $signal->escalated_at = null;
                    $signal->escalated_by = null;
                }
            }
            $signal->save();
        }

        RiskSignal::query()
            ->whereIn('rule_code', self::MANAGED_RULES)
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->where('last_observed_at', '<', $syncStartedAt)
            ->update([
                'status' => 'resolved',
                'resolved_at' => $syncStartedAt,
                'resolution_note' => 'Ditutup otomatis karena kondisi sumber sudah tidak memenuhi aturan risiko.',
                'updated_at' => $syncStartedAt,
            ]);

        return RiskSignal::query()->with(['department', 'kpi', 'actions'])->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")->orderBy('due_at')->orderByDesc('detected_at')->get();
    }

    private function kpiCandidates(CarbonInterface $asOf): Collection
    {
        $overview = $this->analytics->overview(null, $asOf);

        return collect($overview['kpis'])
            ->filter(fn (array $kpi) => in_array($kpi['status'], ['watch', 'critical'], true))
            ->map(fn (array $kpi) => [
                'fingerprint' => 'kpi:'.$kpi['id'],
                'rule_code' => 'KPI_EXCEPTION',
                'category' => 'kpi',
                'severity' => $kpi['status'] === 'critical' ? 'critical' : 'medium',
                'department_id' => data_get($kpi, 'department.id'),
                'kpi_definition_id' => $kpi['id'],
                'source_type' => 'kpi_definition',
                'source_id' => $kpi['id'],
                'source_reference' => $kpi['code'],
                'title' => $kpi['code'].' · '.$kpi['name'],
                'description' => sprintf('Realisasi %s %s dibanding target %s %s. Status %s.', $kpi['actual'], $kpi['unit'], $kpi['target'], $kpi['unit'], $kpi['status']),
                'due_at' => $kpi['status'] === 'critical' ? $asOf->copy()->addDays(3) : $asOf->copy()->addDays(7),
            ]);
    }

    private function findingCandidates(CarbonInterface $asOf, Collection $departments): Collection
    {
        return ComplianceFinding::query()->whereNot('status', 'closed')->whereNotNull('due_at')->where('due_at', '<', $asOf)->get()->map(fn ($finding) => [
            'fingerprint' => 'finding:'.$finding->id.':overdue',
            'rule_code' => 'FINDING_OVERDUE',
            'category' => 'compliance',
            'severity' => in_array($finding->severity, ['high', 'critical'], true) ? 'critical' : 'high',
            'department_id' => $departments->get('quality')?->id,
            'kpi_definition_id' => null,
            'source_type' => 'compliance_finding',
            'source_id' => $finding->id,
            'source_reference' => $finding->reference,
            'title' => 'Temuan melewati tenggat · '.$finding->reference,
            'description' => $finding->title.' belum ditutup setelah '.optional($finding->due_at)->format('d M Y').'.',
            'due_at' => $finding->due_at,
        ]);
    }

    private function capaCandidates(CarbonInterface $asOf, Collection $departments): Collection
    {
        return CorrectiveAction::query()->with('finding')->whereNot('status', 'completed')->whereDate('due_date', '<', $asOf)->get()->map(fn ($action) => [
            'fingerprint' => 'capa:'.$action->id.':overdue',
            'rule_code' => 'CAPA_OVERDUE',
            'category' => 'compliance',
            'severity' => 'high',
            'department_id' => $departments->get('quality')?->id,
            'kpi_definition_id' => null,
            'source_type' => 'corrective_action',
            'source_id' => $action->id,
            'source_reference' => $action->finding?->reference,
            'title' => 'CAPA melewati tenggat · '.$action->title,
            'description' => 'Corrective action belum selesai. Pemilik: '.$action->owner_name.'.',
            'due_at' => $action->due_date?->endOfDay(),
        ]);
    }

    private function receivableCandidates(CarbonInterface $asOf, Collection $departments): Collection
    {
        return FinanceInvoice::query()->with('payments')->whereNot('status', 'void')->whereDate('due_on', '<', $asOf)->get()->map(function ($invoice) use ($departments, $asOf) {
            $paid = $invoice->payments->whereNull('reversed_at')->sum('amount');
            $outstanding = max(0, (float) $invoice->amount - (float) $paid);
            if ($outstanding <= 0.005) return null;
            $days = $invoice->due_on->diffInDays($asOf);
            return [
                'fingerprint' => 'invoice:'.$invoice->id.':overdue',
                'rule_code' => 'RECEIVABLE_OVERDUE',
                'category' => 'finance',
                'severity' => $days > 60 ? 'critical' : ($days > 30 ? 'high' : 'medium'),
                'department_id' => $departments->get('finance')?->id,
                'kpi_definition_id' => null,
                'source_type' => 'finance_invoice',
                'source_id' => $invoice->id,
                'source_reference' => $invoice->invoice_number,
                'title' => 'Piutang jatuh tempo · '.$invoice->invoice_number,
                'description' => 'Outstanding Rp'.number_format($outstanding, 0, ',', '.').' dari '.$invoice->customer_name.'.',
                'due_at' => $invoice->due_on->endOfDay(),
            ];
        })->filter()->values();
    }

    private function certificateCandidates(CarbonInterface $asOf, Collection $departments): Collection
    {
        return CertificationBatch::query()->with('issuances')->whereNotNull('certificate_due_at')->where('certificate_due_at', '<', $asOf)->get()->map(function ($batch) use ($departments, $asOf) {
            $issued = $batch->issuances->sum('issued_count');
            $backlog = max(0, (int) $batch->passed - (int) $issued);
            if ($backlog < 1) return null;
            return [
                'fingerprint' => 'cert-batch:'.$batch->id.':backlog',
                'rule_code' => 'CERTIFICATE_BACKLOG',
                'category' => 'certification',
                'severity' => $batch->certificate_due_at->diffInDays($asOf) > 14 ? 'critical' : 'high',
                'department_id' => $departments->get('certification')?->id,
                'kpi_definition_id' => null,
                'source_type' => 'certification_batch',
                'source_id' => $batch->id,
                'source_reference' => 'BATCH-'.$batch->id,
                'title' => 'Backlog sertifikat melewati SLA',
                'description' => $backlog.' sertifikat belum diterbitkan setelah tenggat.',
                'due_at' => $batch->certificate_due_at,
            ];
        })->filter()->values();
    }

    private function incidentCandidates(CarbonInterface $asOf, Collection $departments): Collection
    {
        return ItIncident::query()->with('service')->whereNot('status', 'resolved')->whereIn('severity', ['high', 'critical'])->get()->map(fn ($incident) => [
            'fingerprint' => 'incident:'.$incident->id.':active',
            'rule_code' => 'IT_INCIDENT',
            'category' => 'it',
            'severity' => $incident->severity,
            'department_id' => $departments->get('it')?->id,
            'kpi_definition_id' => null,
            'source_type' => 'it_incident',
            'source_id' => $incident->id,
            'source_reference' => $incident->reference,
            'title' => 'Insiden TI aktif · '.$incident->reference,
            'description' => ($incident->service?->name ?? 'Layanan TI').' · '.$incident->summary,
            'due_at' => $incident->started_at?->copy()->addHours($incident->severity === 'critical' ? 4 : 12),
        ]);
    }

    private function obligationCandidates(CarbonInterface $asOf, Collection $departments): Collection
    {
        $limit = $asOf->copy()->addDays(90);
        return ComplianceObligation::query()->where('status', 'active')->whereNotNull('valid_until')->whereDate('valid_until', '<=', $limit)->get()->map(function ($obligation) use ($asOf, $departments) {
            $expired = $obligation->valid_until->lt($asOf);
            $days = $expired ? 0 : $asOf->diffInDays($obligation->valid_until);
            return [
                'fingerprint' => 'obligation:'.$obligation->id.':expiry',
                'rule_code' => 'OBLIGATION_EXPIRY',
                'category' => 'compliance',
                'severity' => $expired || $days <= 30 ? 'critical' : ($days <= 60 ? 'high' : 'medium'),
                'department_id' => $departments->get('quality')?->id,
                'kpi_definition_id' => null,
                'source_type' => 'compliance_obligation',
                'source_id' => $obligation->id,
                'source_reference' => $obligation->code,
                'title' => ($expired ? 'Kewajiban kedaluwarsa · ' : 'Kewajiban segera berakhir · ').$obligation->code,
                'description' => $obligation->title.' berlaku sampai '.$obligation->valid_until->format('d M Y').'.',
                'due_at' => $obligation->valid_until->endOfDay(),
            ];
        });
    }
}
