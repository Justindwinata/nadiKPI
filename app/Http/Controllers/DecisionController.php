<?php

namespace App\Http\Controllers;

use App\Models\ActionItem;
use App\Models\AuditLog;
use App\Models\ManagementReview;
use App\Models\ManagementReviewItem;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DecisionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Read endpoints must not mutate operational risk state. Risk detection and
        // auto-resolution are owned by the scheduled nadi:monitor-risks command.
        $user = $request->user();
        $signalQuery = RiskSignal::query()->with(['department', 'kpi', 'actions' => fn ($query) => $query->orderByDesc('id')]);
        $this->scopeSignals($signalQuery, $user);

        $actionQuery = ActionItem::query()->with(['department', 'kpi', 'riskSignal']);
        $this->scopeActions($actionQuery, $user);

        $activeSignals = (clone $signalQuery)->whereNotIn('status', ['resolved', 'dismissed']);
        $actions = $actionQuery->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'blocked' THEN 3 ELSE 4 END")->orderBy('due_date')->get();
        $reviews = ManagementReview::query()->with(['items.signal.department', 'items.action'])->orderByDesc('period_end')->orderByDesc('id')->take(12)->get();

        return response()->json([
            'summary' => [
                'active_signals' => (clone $activeSignals)->count(),
                'critical_signals' => (clone $activeSignals)->where('severity', 'critical')->count(),
                'unacknowledged_signals' => (clone $activeSignals)->whereNull('acknowledged_at')->count(),
                'escalated_signals' => (clone $activeSignals)->where('escalation_level', '>', 0)->count(),
                'open_actions' => $actions->where('status', '!=', 'completed')->count(),
                'overdue_actions' => $actions->where('status', '!=', 'completed')->filter(fn ($action) => $action->due_date?->isPast())->count(),
            ],
            'signals' => $signalQuery->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")->orderBy('due_at')->orderByDesc('detected_at')->take(100)->get(),
            'actions' => $actions,
            'reviews' => $user->hasPermission('decisions.review') ? $reviews : collect(),
            'permissions' => [
                'manage' => $user->hasPermission('decisions.manage'),
                'review' => $user->hasPermission('decisions.review'),
            ],
        ]);
    }

    public function acknowledge(Request $request, RiskSignal $signal): JsonResponse
    {
        $signal = DB::transaction(function () use ($request, $signal): RiskSignal {
            $locked = RiskSignal::query()->whereKey($signal->id)->lockForUpdate()->firstOrFail();
            $this->authorizeSignal($request->user(), $locked);
            if (in_array($locked->status, ['resolved', 'dismissed'], true)) {
                throw ValidationException::withMessages(['signal' => 'Signal yang sudah ditutup tidak dapat diakui kembali.']);
            }

            $before = $locked->only(['status', 'acknowledged_at', 'acknowledged_by']);
            $locked->update([
                'status' => $locked->status === 'open' ? 'acknowledged' : $locked->status,
                'acknowledged_at' => $locked->acknowledged_at ?? now(),
                'acknowledged_by' => $locked->acknowledged_by ?? $request->user()->id,
            ]);
            $this->audit($request, 'acknowledge_risk_signal', RiskSignal::class, $locked->id, ['before' => $before, 'after' => $locked->fresh()->only(['status', 'acknowledged_at', 'acknowledged_by'])]);

            return $locked->fresh();
        });

        return response()->json(['signal' => $signal->load(['department', 'kpi', 'actions'])]);
    }

    public function escalate(Request $request, RiskSignal $signal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:1000']]);

        $signal = DB::transaction(function () use ($request, $signal, $data): RiskSignal {
            $locked = RiskSignal::query()->whereKey($signal->id)->lockForUpdate()->firstOrFail();
            $this->authorizeSignal($request->user(), $locked);
            if (in_array($locked->status, ['resolved', 'dismissed'], true)) {
                throw ValidationException::withMessages(['signal' => 'Signal yang sudah ditutup tidak dapat dieskalasi.']);
            }

            $before = $locked->only(['status', 'severity', 'escalation_level']);
            $level = min(3, ((int) $locked->escalation_level) + 1);
            $locked->update([
                'status' => 'in_progress',
                'severity' => $level >= 2 ? 'critical' : ($locked->severity === 'medium' ? 'high' : $locked->severity),
                'escalation_level' => $level,
                'escalated_at' => now(),
                'escalated_by' => $request->user()->id,
                'acknowledged_at' => $locked->acknowledged_at ?? now(),
                'acknowledged_by' => $locked->acknowledged_by ?? $request->user()->id,
            ]);

            $linkedActions = ActionItem::query()
                ->where('risk_signal_id', $locked->id)
                ->whereNot('status', 'completed')
                ->lockForUpdate()
                ->get();
            foreach ($linkedActions as $action) {
                $action->update([
                    'escalation_level' => $level,
                    'escalated_at' => now(),
                    'escalated_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                    'priority' => $level >= 2 ? 'critical' : ($action->priority === 'low' ? 'medium' : $action->priority),
                ]);
            }

            $this->audit($request, 'escalate_risk_signal', RiskSignal::class, $locked->id, ['before' => $before, 'after' => $locked->fresh()->only(['status', 'severity', 'escalation_level']), 'reason' => $data['reason']]);

            return $locked->fresh();
        });

        return response()->json(['signal' => $signal->load(['department', 'kpi', 'actions'])]);
    }

    public function resolve(Request $request, RiskSignal $signal): JsonResponse
    {
        $data = $request->validate(['resolution_note' => ['required', 'string', 'min:12', 'max:2000']]);

        $signal = DB::transaction(function () use ($request, $signal, $data): RiskSignal {
            $locked = RiskSignal::query()->whereKey($signal->id)->lockForUpdate()->firstOrFail();
            $this->authorizeSignal($request->user(), $locked);
            if (in_array($locked->status, ['resolved', 'dismissed'], true)) {
                throw ValidationException::withMessages(['signal' => 'Signal yang sudah ditutup tidak dapat diselesaikan kembali.']);
            }

            $before = $locked->only(['status', 'resolved_at', 'resolved_by', 'resolution_note']);
            $locked->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $request->user()->id, 'resolution_note' => $data['resolution_note']]);
            $this->audit($request, 'resolve_risk_signal', RiskSignal::class, $locked->id, ['before' => $before, 'after' => $locked->fresh()->only(['status', 'resolved_at', 'resolved_by', 'resolution_note'])]);

            return $locked->fresh();
        });

        return response()->json(['signal' => $signal->load(['department', 'kpi', 'actions'])]);
    }

    public function createAction(Request $request, RiskSignal $signal): JsonResponse
    {
        $this->authorizeSignal($request->user(), $signal);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1200'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'owner_name' => ['required', 'string', 'max:100'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'decision_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $action = DB::transaction(function () use ($request, $signal, $data): ActionItem {
            $locked = RiskSignal::query()->whereKey($signal->id)->lockForUpdate()->firstOrFail();
            $this->authorizeSignal($request->user(), $locked);
            if (in_array($locked->status, ['resolved', 'dismissed'], true)) {
                throw ValidationException::withMessages(['signal' => 'Signal yang sudah ditutup tidak dapat diberi tindak lanjut baru.']);
            }

            $action = ActionItem::create($data + [
                'department_id' => $locked->department_id,
                'kpi_definition_id' => $locked->kpi_definition_id,
                'risk_signal_id' => $locked->id,
                'status' => 'open',
                'created_by' => $actor->id,
                'updated_by' => $request->user()->id,
            ]);
            if (! $locked->acknowledged_at) {
                $locked->update(['status' => 'in_progress', 'acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id]);
            }
            $this->audit($request, 'create_decision_action', ActionItem::class, $action->id, ['risk_signal_id' => $locked->id, 'data' => $data]);
            return $action;
        });

        return response()->json(['action' => $action->load(['department', 'kpi', 'riskSignal'])], 201);
    }

    public function storeReview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'meeting_at' => ['nullable', 'date'],
            'chair_name' => ['required', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'signal_ids' => ['required', 'array', 'min:1', 'max:100'],
            'signal_ids.*' => ['integer', 'distinct', Rule::exists('risk_signals', 'id')],
        ]);

        $review = DB::transaction(function () use ($request, $data): ManagementReview {
            $actor = User::query()
                ->with(['department', 'permissionOverrides'])
                ->whereKey($request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($actor->is_active && $actor->hasPermission('decisions.review'), 403);

            $signalIds = collect($data['signal_ids'])->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $signalQuery = RiskSignal::query()->whereIn('id', $signalIds)->orderBy('id')->lockForUpdate();
            if ($actor->role !== 'director' && $actor->department?->code !== 'quality') {
                $signalQuery->where('department_id', $actor->department_id);
            }
            $signals = $signalQuery->get();
            if ($signals->count() !== $signalIds->count()) {
                throw ValidationException::withMessages([
                    'signal_ids' => 'Satu atau lebih risk signal tidak tersedia dalam scope Management Review Anda.',
                ]);
            }

            do { $reference = 'MR-'.now()->format('Ym').'-'.Str::upper(Str::random(6)); } while (ManagementReview::where('reference', $reference)->exists());
            $review = ManagementReview::create([
                'reference' => $reference,
                'title' => $data['title'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'meeting_at' => $data['meeting_at'] ?? null,
                'chair_name' => $data['chair_name'],
                'summary' => $data['summary'] ?? null,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
            foreach ($signals as $signal) {
                ManagementReviewItem::create([
                    'management_review_id' => $review->id,
                    'risk_signal_id' => $signal->id,
                    'action_item_id' => $signal->actions()->whereNot('status', 'completed')->latest()->value('id'),
                    'title' => $signal->title,
                    'owner_name' => $signal->actions()->latest()->value('owner_name'),
                    'due_date' => $signal->actions()->latest()->value('due_date'),
                    'status' => $signal->status === 'resolved' ? 'completed' : 'open',
                ]);
            }
            $this->audit($request, 'create_management_review', ManagementReview::class, $review->id, ['reference' => $reference, 'signal_ids' => $data['signal_ids']]);
            return $review;
        });

        return response()->json(['review' => $review->load(['items.signal.department', 'items.action'])], 201);
    }

    public function updateReview(Request $request, ManagementReview $review): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['draft', 'in_review', 'approved', 'closed'])],
            'meeting_at' => ['nullable', 'date'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'decisions' => ['nullable', 'string', 'max:8000'],
        ]);

        $review = DB::transaction(function () use ($request, $review, $data): ManagementReview {
            $locked = ManagementReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $allowedTransitions = [
                'draft' => ['draft', 'in_review'],
                'in_review' => ['in_review', 'approved'],
                'approved' => ['approved', 'closed'],
                'closed' => ['closed'],
            ];
            if (! in_array($data['status'], $allowedTransitions[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Status management review harus mengikuti urutan draft → in review → approved → closed.']);
            }
            if (in_array($data['status'], ['approved', 'closed'], true) && blank($data['decisions'] ?? $locked->decisions)) {
                throw ValidationException::withMessages(['decisions' => 'Keputusan management review wajib diisi sebelum persetujuan.']);
            }

            $before = $locked->only(['status', 'meeting_at', 'summary', 'decisions']);
            $payload = $data;
            if ($data['status'] === 'approved' && ! $locked->approved_at) {
                $payload['approved_at'] = now();
                $payload['approved_by'] = $request->user()->id;
            }
            if ($data['status'] === 'closed' && ! $locked->closed_at) {
                $payload['closed_at'] = now();
            }
            $locked->update($payload);
            $this->audit($request, 'update_management_review', ManagementReview::class, $locked->id, ['before' => $before, 'after' => $locked->fresh()->only(['status', 'meeting_at', 'summary', 'decisions'])]);

            return $locked->fresh();
        });

        return response()->json(['review' => $review->load(['items.signal.department', 'items.action'])]);
    }

    public function updateReviewItem(Request $request, ManagementReview $review, ManagementReviewItem $item): JsonResponse
    {
        abort_unless($item->management_review_id === $review->id, 404);
        $data = $request->validate([
            'decision' => ['nullable', 'string', 'max:3000'],
            'owner_name' => ['nullable', 'string', 'max:160'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['open', 'in_progress', 'completed'])],
        ]);

        $item = DB::transaction(function () use ($request, $review, $item, $data): ManagementReviewItem {
            $lockedReview = ManagementReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $lockedItem = ManagementReviewItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedItem->management_review_id === $lockedReview->id, 404);

            if (in_array($lockedReview->status, ['approved', 'closed'], true)) {
                throw ValidationException::withMessages(['review' => 'Item tidak dapat diubah setelah management review disetujui.']);
            }

            $allowedItemTransitions = [
                'open' => ['open', 'in_progress', 'completed'],
                'in_progress' => ['in_progress', 'completed'],
                'completed' => ['completed'],
            ];
            if (! in_array($data['status'], $allowedItemTransitions[$lockedItem->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Status item management review tidak dapat dimundurkan.']);
            }

            $before = $lockedItem->only(['decision', 'owner_name', 'due_date', 'status']);
            $lockedItem->update($data);
            $this->audit($request, 'update_management_review_item', ManagementReviewItem::class, $lockedItem->id, ['before' => $before, 'after' => $lockedItem->fresh()->only(['decision', 'owner_name', 'due_date', 'status'])]);

            return $lockedItem->fresh();
        });

        return response()->json(['item' => $item->load(['signal.department', 'action'])]);
    }

    private function authorizeSignal(User $user, RiskSignal $signal): void
    {
        abort_unless($user->hasPermission('decisions.manage'), 403);
        if ($user->role === 'director' || $user->department?->code === 'quality') return;
        abort_unless($signal->department_id && $signal->department_id === $user->department_id, 403);
    }

    private function scopeSignals(Builder $query, User $user): void
    {
        if ($user->role === 'director' || $user->department?->code === 'quality') return;
        $query->where('department_id', $user->department_id);
    }

    private function scopeActions(Builder $query, User $user): void
    {
        if ($user->role === 'director' || $user->department?->code === 'quality') return;
        $query->where('department_id', $user->department_id);
    }

    private function audit(Request $request, string $action, string $entityType, int $entityId, array $changes): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId, 'changes' => $changes, 'ip_address' => $request->ip()]);
    }
}
