<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreActionItemRequest;
use App\Http\Requests\UpdateActionItemRequest;
use App\Models\ActionItem;
use App\Models\AuditLog;
use App\Models\RiskSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActionItemController extends Controller
{
    public function store(StoreActionItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $action = DB::transaction(function () use ($data, $request): ActionItem {
            $action = ActionItem::create($data + ['status' => 'open', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'create', 'entity_type' => ActionItem::class, 'entity_id' => $action->id, 'changes' => $data, 'ip_address' => $request->ip()]);

            return $action;
        });

        return response()->json(['action' => $action->load(['department', 'kpi', 'riskSignal'])], 201);
    }

    public function update(UpdateActionItemRequest $request, ActionItem $action): JsonResponse
    {
        $data = $request->validated();

        $action = DB::transaction(function () use ($action, $data, $request): ActionItem {
            // DecisionController locks RiskSignal before linked ActionItem. Keep the same order here
            // to avoid deadlocks when escalation and action completion happen concurrently.
            $lockedSignal = null;
            if ($action->risk_signal_id) {
                $lockedSignal = RiskSignal::query()->whereKey($action->risk_signal_id)->lockForUpdate()->first();
            }
            $locked = ActionItem::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();

            $allowed = [
                'open' => ['open', 'in_progress', 'blocked', 'completed'],
                'in_progress' => ['in_progress', 'blocked', 'completed'],
                'blocked' => ['blocked', 'in_progress', 'completed'],
                'completed' => ['completed'],
            ];
            if (! in_array($data['status'], $allowed[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Status tindak lanjut tidak dapat dimundurkan dari tahap saat ini.']);
            }

            $before = $locked->only(['status', 'acknowledged_at', 'completed_at', 'resolution_note', 'resolution_evidence']);
            $payload = [
                'status' => $data['status'],
                'updated_by' => $request->user()->id,
                'completed_at' => $data['status'] === 'completed' ? ($locked->completed_at ?? now()) : null,
            ];
            if ($data['status'] === 'in_progress' && ! $locked->acknowledged_at) {
                $payload['acknowledged_at'] = now();
                $payload['acknowledged_by'] = $request->user()->id;
            }
            if ($data['status'] === 'completed') {
                $payload['acknowledged_at'] = $locked->acknowledged_at ?? now();
                $payload['acknowledged_by'] = $locked->acknowledged_by ?? $request->user()->id;
                $payload['resolution_note'] = $data['resolution_note'];
                $payload['resolution_evidence'] = $data['resolution_evidence'];
            }
            $locked->update($payload);

            if ($data['status'] === 'completed' && $lockedSignal && ! in_array($lockedSignal->status, ['resolved', 'dismissed'], true)) {
                $hasOpenLinkedActions = ActionItem::query()
                    ->where('risk_signal_id', $lockedSignal->id)
                    ->whereNot('status', 'completed')
                    ->exists();

                if (! $hasOpenLinkedActions) {
                    $lockedSignal->update([
                        'status' => 'resolved',
                        'resolved_at' => now(),
                        'resolved_by' => $request->user()->id,
                        'resolution_note' => 'Seluruh tindak lanjut selesai. Action terakhir #'.$locked->id.': '.$data['resolution_note'],
                    ]);
                }
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'update_action_workflow',
                'entity_type' => ActionItem::class,
                'entity_id' => $locked->id,
                'changes' => ['before' => $before, 'after' => $locked->fresh()->only(['status', 'acknowledged_at', 'completed_at', 'resolution_note', 'resolution_evidence'])],
                'ip_address' => $request->ip(),
            ]);

            return $locked->fresh();
        });

        return response()->json(['action' => $action->load(['department', 'kpi', 'riskSignal'])]);
    }
}
