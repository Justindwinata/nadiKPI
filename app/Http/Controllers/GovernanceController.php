<?php

namespace App\Http\Controllers;

use App\Models\Assessor;
use App\Models\AuditLog;
use App\Models\CertificationAppeal;
use App\Models\CertificationBatch;
use App\Models\CertificationScheme;
use App\Models\ComplianceFinding;
use App\Models\ComplianceObligation;
use App\Models\CorrectiveAction;
use App\Models\DataImportBatch;
use App\Models\DataSource;
use App\Models\Tuk;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GovernanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $now = CarbonImmutable::now();
        $riskHorizon = $now->addDays(90)->endOfDay();

        $findings = ComplianceFinding::query()
            ->with(['actions' => fn ($query) => $query->orderBy('due_date')])
            ->latest('opened_at')
            ->limit(100)
            ->get();
        $actions = CorrectiveAction::query()->get();
        $appeals = CertificationAppeal::query()
            ->with('batch:id,code,certification_scheme_id')
            ->latest('received_at')
            ->limit(100)
            ->get();
        $imports = DataImportBatch::query()
            ->with(['source:id,code,name,source_type,authority_rank', 'creator:id,name'])
            ->latest('imported_at')
            ->limit(100)
            ->get();

        $expiryRisks = collect()
            ->concat(Assessor::query()->whereNull('archived_at')->whereNotNull('valid_until')->where('status', '!=', 'inactive')->whereDate('valid_until', '<=', $riskHorizon)->get()->map(fn ($row) => $this->expiryRisk('assessor', $row->registration_no, $row->name, $row->valid_until, $row->status)))
            ->concat(Tuk::query()->whereNull('archived_at')->whereNotNull('verification_valid_until')->where('status', '!=', 'inactive')->whereDate('verification_valid_until', '<=', $riskHorizon)->get()->map(fn ($row) => $this->expiryRisk('tuk', $row->code, $row->name, $row->verification_valid_until, $row->status)))
            ->concat(CertificationScheme::query()->whereNull('archived_at')->whereNotNull('valid_until')->where('is_active', true)->whereDate('valid_until', '<=', $riskHorizon)->get()->map(fn ($row) => $this->expiryRisk('scheme', $row->code, $row->name, $row->valid_until, $row->is_active ? 'active' : 'inactive')))
            ->concat(ComplianceObligation::query()->whereNotNull('valid_until')->where('status', '!=', 'closed')->whereDate('valid_until', '<=', $riskHorizon)->get()->map(fn ($row) => $this->expiryRisk('obligation', $row->code, $row->title, $row->valid_until, $row->status)))
            ->sortBy('valid_until')
            ->values();

        return response()->json([
            'summary' => [
                'open_findings' => $findings->where('status', '!=', 'closed')->count(),
                'overdue_capa' => $actions->filter(fn (CorrectiveAction $row) => $row->status !== 'completed' && $row->due_date?->isPast())->count(),
                'open_appeals' => $appeals->whereNotIn('status', ['decided', 'closed'])->count(),
                'expiry_risks_90d' => $expiryRisks->count(),
                'import_exceptions' => $imports->where('reconciliation_status', 'exception')->count(),
            ],
            'findings' => $findings,
            'appeals' => $appeals,
            'obligations' => ComplianceObligation::query()->orderByRaw('valid_until IS NULL, valid_until')->get(),
            'expiry_risks' => $expiryRisks,
            'data_sources' => DataSource::query()->orderByDesc('authority_rank')->orderBy('name')->get(),
            'imports' => $imports,
            'audit_logs' => $request->user()->hasPermission('governance.audit.view')
                ? AuditLog::query()->with('user:id,name')->latest()->limit(60)->get()->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'user' => $log->user?->name ?? 'Sistem',
                    'action' => $log->action,
                    'entity_type' => class_basename($log->entity_type),
                    'entity_id' => $log->entity_id,
                    'changes' => $log->changes,
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])
                : collect(),
            'catalogs' => [
                'certification_batches' => CertificationBatch::query()->whereIn('status', ['decision', 'completed'])->select(['id', 'code', 'assessment_date', 'status'])->latest('assessment_date')->limit(100)->get(),
            ],
            'permissions' => [
                'admin_governance' => $request->user()->hasPermission('governance.registry.manage') || $request->user()->hasPermission('governance.provenance.manage'),
                'manage_registry' => $request->user()->hasPermission('governance.registry.manage'),
                'manage_provenance' => $request->user()->hasPermission('governance.provenance.manage'),
                'manage_findings' => $request->user()->hasPermission('governance.findings.manage'),
                'manage_appeals' => $request->user()->hasPermission('governance.appeals.manage'),
                'view_audit' => $request->user()->hasPermission('governance.audit.view'),
            ],
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    public function storeFinding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:80', Rule::unique('compliance_findings', 'reference')],
            'source' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:80'],
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:4000'],
            'owner_name' => ['required', 'string', 'max:160'],
            'opened_at' => ['required', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:opened_at'],
        ]);

        $finding = DB::transaction(function () use ($data, $request) {
            $finding = ComplianceFinding::create($data + ['status' => 'open', 'created_by' => $request->user()->id]);
            $this->audit($request, 'create_finding', $finding, $data);

            return $finding;
        });

        return response()->json(['finding' => $finding->load('actions')], 201);
    }

    public function updateFinding(Request $request, ComplianceFinding $finding): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'closed'])],
            'closure_evidence' => ['nullable', 'string', 'max:4000'],
        ]);

        $finding = DB::transaction(function () use ($data, $request, $finding): ComplianceFinding {
            $locked = ComplianceFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();
            $stages = ['open' => 0, 'in_progress' => 1, 'closed' => 2];
            if (($stages[$data['status']] ?? -1) < ($stages[$locked->status] ?? -1)) {
                throw ValidationException::withMessages(['status' => 'Status temuan tidak dapat dikembalikan ke tahap sebelumnya.']);
            }

            if ($data['status'] === 'closed') {
                if (blank($data['closure_evidence'] ?? null)) {
                    throw ValidationException::withMessages(['closure_evidence' => 'Bukti penutupan wajib diisi sebelum temuan ditutup.']);
                }
                if ($locked->actions()->where('status', '!=', 'completed')->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['status' => 'Semua CAPA harus selesai sebelum temuan dapat ditutup.']);
                }
            }

            $before = $locked->only(['status', 'closed_at', 'closure_evidence']);
            $locked->update([
                'status' => $data['status'],
                'closure_evidence' => $data['closure_evidence'] ?? $locked->closure_evidence,
                'closed_at' => $data['status'] === 'closed' ? now() : null,
            ]);
            $this->audit($request, 'update_finding', $locked, ['before' => $before, 'after' => $locked->fresh()->only(array_keys($before))]);

            return $locked->fresh('actions');
        });

        return response()->json(['finding' => $finding]);
    }

    public function storeCorrectiveAction(Request $request, ComplianceFinding $finding): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:4000'],
            'owner_name' => ['required', 'string', 'max:160'],
            'due_date' => ['required', 'date'],
        ]);

        $action = DB::transaction(function () use ($request, $finding, $data): CorrectiveAction {
            $lockedFinding = ComplianceFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedFinding->status === 'closed', 422, 'Temuan yang sudah ditutup tidak dapat menerima CAPA baru.');

            $action = CorrectiveAction::create($data + ['compliance_finding_id' => $lockedFinding->id, 'status' => 'open', 'created_by' => $request->user()->id]);
            if ($lockedFinding->status === 'open') {
                $lockedFinding->update(['status' => 'in_progress']);
            }
            $this->audit($request, 'create_capa', $action, $data + ['finding_reference' => $lockedFinding->reference]);

            return $action;
        });

        return response()->json(['action' => $action], 201);
    }

    public function updateCorrectiveAction(Request $request, CorrectiveAction $action): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'blocked', 'completed'])],
            'evidence' => ['nullable', 'string', 'max:4000'],
        ]);
        if ($data['status'] === 'completed' && blank($data['evidence'] ?? null)) {
            throw ValidationException::withMessages(['evidence' => 'Bukti implementasi wajib diisi untuk menyelesaikan CAPA.']);
        }
        $action = DB::transaction(function () use ($request, $action, $data): CorrectiveAction {
            $locked = CorrectiveAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed' && $data['status'] !== 'completed') {
                throw ValidationException::withMessages(['status' => 'CAPA yang sudah selesai tidak dapat dibuka kembali.']);
            }
            $before = $locked->only(['status', 'evidence', 'completed_at']);
            $locked->update([
                'status' => $data['status'],
                'evidence' => $data['evidence'] ?? $locked->evidence,
                'completed_at' => $data['status'] === 'completed' ? now() : null,
            ]);
            $this->audit($request, 'update_capa', $locked, ['before' => $before, 'after' => $locked->fresh()->only(array_keys($before))]);

            return $locked->fresh();
        });

        return response()->json(['action' => $action]);
    }

    public function storeAppeal(Request $request): JsonResponse
    {
        abort_unless($this->canManageAppeals($request), 403);
        $data = $request->validate([
            'certification_batch_id' => ['required', 'integer', 'exists:certification_batches,id'],
            'reference' => ['required', 'string', 'max:80', Rule::unique('certification_appeals', 'reference')],
            'appellant_reference' => ['required', 'string', 'max:120'],
            'received_at' => ['required', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:received_at'],
            'reason' => ['required', 'string', 'max:4000'],
            'owner_name' => ['required', 'string', 'max:160'],
        ]);
        $appeal = DB::transaction(function () use ($request, $data): CertificationAppeal {
            $appeal = CertificationAppeal::create($data + ['status' => 'received', 'created_by' => $request->user()->id]);
            $this->audit($request, 'create_appeal', $appeal, $data);

            return $appeal;
        });

        return response()->json(['appeal' => $appeal->load('batch:id,code')], 201);
    }

    public function updateAppeal(Request $request, CertificationAppeal $appeal): JsonResponse
    {
        abort_unless($this->canManageAppeals($request), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['received', 'reviewing', 'decided', 'closed'])],
            'decision' => ['nullable', Rule::in(['maintained', 'changed', 'reassessment'])],
            'resolution_summary' => ['nullable', 'string', 'max:4000'],
            'decision_at' => ['nullable', 'date', 'after_or_equal:'.$appeal->received_at->toDateTimeString()],
        ]);
        $appeal = DB::transaction(function () use ($request, $appeal, $data): CertificationAppeal {
            $locked = CertificationAppeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            $stages = ['received' => 0, 'reviewing' => 1, 'decided' => 2, 'closed' => 3];
            if (($stages[$data['status']] ?? -1) < ($stages[$locked->status] ?? -1)) {
                throw ValidationException::withMessages(['status' => 'Status banding tidak dapat dikembalikan ke tahap sebelumnya.']);
            }

            $effectiveDecision = $data['decision'] ?? $locked->decision;
            $effectiveResolution = $data['resolution_summary'] ?? $locked->resolution_summary;
            if (in_array($data['status'], ['decided', 'closed'], true) && (blank($effectiveDecision) || blank($effectiveResolution))) {
                throw ValidationException::withMessages(['decision' => 'Keputusan dan ringkasan resolusi wajib tersedia sebelum banding diputuskan/ditutup.']);
            }

            $decisionAt = in_array($data['status'], ['decided', 'closed'], true)
                ? ($data['decision_at'] ?? $locked->decision_at ?? now())
                : null;
            if ($decisionAt && CarbonImmutable::parse($decisionAt)->lt($locked->received_at)) {
                throw ValidationException::withMessages(['decision_at' => 'Waktu keputusan tidak boleh lebih awal dari waktu penerimaan banding.']);
            }

            $before = $locked->only(['status', 'decision', 'resolution_summary', 'decision_at']);
            $locked->update([
                'status' => $data['status'],
                'decision' => $effectiveDecision,
                'resolution_summary' => $effectiveResolution,
                'decision_at' => $decisionAt,
            ]);
            $this->audit($request, 'update_appeal', $locked, ['before' => $before, 'after' => $locked->fresh()->only(array_keys($before))]);

            return $locked->fresh('batch:id,code');
        });

        return response()->json(['appeal' => $appeal]);
    }

    public function storeObligation(Request $request): JsonResponse
    {
        abort_unless($this->isGovernanceAdmin($request), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', Rule::unique('compliance_obligations', 'code')],
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:60'],
            'authority' => ['nullable', 'string', 'max:160'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', Rule::in(['active', 'review', 'closed'])],
            'owner_name' => ['required', 'string', 'max:160'],
            'evidence_reference' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $obligation = DB::transaction(function () use ($request, $data): ComplianceObligation {
            $obligation = ComplianceObligation::create($data + ['created_by' => $request->user()->id]);
            $this->audit($request, 'create_obligation', $obligation, $data);

            return $obligation;
        });

        return response()->json(['obligation' => $obligation], 201);
    }

    public function storeDataSource(Request $request): JsonResponse
    {
        abort_unless($this->isGovernanceAdmin($request), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('data_sources', 'code')],
            'name' => ['required', 'string', 'max:160'],
            'source_type' => ['required', Rule::in(['internal', 'regulatory', 'public', 'manual'])],
            'authority_rank' => ['required', 'integer', 'between:1,100'],
            'owner_name' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $source = DB::transaction(function () use ($request, $data): DataSource {
            $source = DataSource::create($data + ['is_active' => true]);
            $this->audit($request, 'create_data_source', $source, $data);

            return $source;
        });

        return response()->json(['source' => $source], 201);
    }

    public function reconcileImport(Request $request, DataImportBatch $batch): JsonResponse
    {
        abort_unless($this->isGovernanceAdmin($request), 403);
        if ($batch->dataset_type) {
            throw ValidationException::withMessages([
                'reconciliation_status' => 'Batch real-data integration direkonsiliasi otomatis oleh proses publish. Kelola exception melalui halaman Integrasi Data.',
            ]);
        }
        $data = $request->validate([
            'reconciliation_status' => ['required', Rule::in(['reconciled', 'exception'])],
            'notes' => ['required', 'string', 'max:2000'],
        ]);
        $batch = DB::transaction(function () use ($request, $batch, $data): DataImportBatch {
            $locked = DataImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->dataset_type) {
                throw ValidationException::withMessages([
                    'reconciliation_status' => 'Batch real-data integration direkonsiliasi otomatis oleh proses publish. Kelola exception melalui halaman Integrasi Data.',
                ]);
            }
            $before = $locked->only(['reconciliation_status', 'notes']);
            $locked->update($data);
            $this->audit($request, 'reconcile_import', $locked, ['before' => $before, 'after' => $locked->fresh()->only(array_keys($before))]);

            return $locked->fresh('source');
        });

        return response()->json(['batch' => $batch]);
    }

    private function expiryRisk(string $type, string $code, string $name, $validUntil, string $status): array
    {
        $date = CarbonImmutable::parse($validUntil);
        $days = CarbonImmutable::today()->diffInDays($date, false);

        return [
            'type' => $type,
            'code' => $code,
            'name' => $name,
            'valid_until' => $date->toDateString(),
            'days_remaining' => $days,
            'status' => $days < 0 ? 'expired' : ($days <= 30 ? 'critical' : 'expiring'),
            'source_status' => $status,
        ];
    }

    private function isGovernanceAdmin(Request $request): bool
    {
        return $request->user()?->hasPermission('governance.registry.manage') || $request->user()?->hasPermission('governance.provenance.manage');
    }

    private function canManageAppeals(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('governance.appeals.manage');
    }

    private function audit(Request $request, string $action, $entity, array $changes): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
