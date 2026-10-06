<?php

namespace App\Services;

use App\Models\ActionItem;
use App\Models\AuditLog;
use App\Models\ManagementReview;
use App\Models\RiskSignal;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationMonitoringService
{
    public function __construct(private readonly RiskSignalService $riskSignals) {}

    public function monitor(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy() : now();

        return DB::transaction(function () use ($asOf): array {
            $signals = $this->riskSignals->sync($asOf);
            $escalated = $this->autoEscalateCriticalSignals($asOf);
            $notifications = 0;

            foreach ($signals->whereNotIn('status', ['resolved', 'dismissed']) as $signal) {
                $notifications += $this->notifyRiskSignal($signal);
                if ($signal->severity === 'critical') {
                    $notifications += $this->notifyCriticalSeverity($signal);
                }
            }

            $notifications += $this->notifyOverdueActions($asOf);
            $notifications += $this->notifyUpcomingReviews($asOf);
            $purged = $this->purgeOldNotifications($asOf);

            return [
                'active_signals' => $signals->whereNotIn('status', ['resolved', 'dismissed'])->count(),
                'auto_escalated' => $escalated,
                'notifications_created' => $notifications,
                'notifications_purged' => $purged,
                'monitored_at' => $asOf->toIso8601String(),
            ];
        });
    }

    private function notifyRiskSignal(RiskSignal $signal): int
    {
        $fingerprint = 'risk:'.$signal->id.':opened:'.optional($signal->detected_at)->timestamp;
        $count = 0;
        foreach ($this->recipientsForDepartment($signal->department_id) as $user) {
            $count += $this->upsert($user, $fingerprint, [
                'risk_signal_id' => $signal->id,
                'type' => 'risk_signal',
                'severity' => $signal->severity,
                'title' => $signal->title,
                'message' => $signal->description,
                'action_url' => '/decisions',
                'data' => ['rule_code' => $signal->rule_code, 'source_reference' => $signal->source_reference],
            ]);
        }
        return $count;
    }

    private function notifyCriticalSeverity(RiskSignal $signal): int
    {
        $fingerprint = 'risk:'.$signal->id.':critical:'.optional($signal->detected_at)->timestamp;
        $count = 0;
        foreach ($this->recipientsForDepartment($signal->department_id, includeCompanyOversight: true) as $user) {
            $count += $this->upsert($user, $fingerprint, [
                'risk_signal_id' => $signal->id,
                'type' => 'critical_risk',
                'severity' => 'critical',
                'title' => 'Kritis · '.$signal->title,
                'message' => 'Kondisi memerlukan perhatian segera. '.$signal->description,
                'action_url' => '/decisions',
                'data' => ['escalation_level' => $signal->escalation_level, 'rule_code' => $signal->rule_code],
            ]);
        }
        return $count;
    }

    private function notifyOverdueActions(CarbonInterface $asOf): int
    {
        $count = 0;
        $actions = ActionItem::query()
            ->whereNot('status', 'completed')
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->get();

        foreach ($actions as $action) {
            $fingerprint = 'action:'.$action->id.':overdue:'.$asOf->toDateString();
            foreach ($this->recipientsForDepartment($action->department_id) as $user) {
                $count += $this->upsert($user, $fingerprint, [
                    'risk_signal_id' => $action->risk_signal_id,
                    'action_item_id' => $action->id,
                    'type' => 'action_overdue',
                    'severity' => in_array($action->priority, ['critical', 'high'], true) ? 'critical' : 'high',
                    'title' => 'Action melewati tenggat · '.$action->title,
                    'message' => 'Tenggat '.$action->due_date?->format('d M Y').' telah lewat. Owner: '.$action->owner_name.'.',
                    'action_url' => '/decisions',
                    'data' => ['priority' => $action->priority, 'due_date' => optional($action->due_date)->toDateString()],
                ]);
            }
        }

        return $count;
    }

    private function notifyUpcomingReviews(CarbonInterface $asOf): int
    {
        $count = 0;
        $reviews = ManagementReview::query()
            ->whereIn('status', ['draft', 'in_review'])
            ->whereNotNull('meeting_at')
            ->whereBetween('meeting_at', [$asOf, $asOf->copy()->addHours(24)])
            ->get();

        foreach ($reviews as $review) {
            $fingerprint = 'review:'.$review->id.':meeting:'.$review->meeting_at->format('YmdHi');
            $recipients = User::query()->where('is_active', true)->with(['department', 'permissionOverrides'])->get()
                ->filter(fn (User $user) => $user->hasPermission('decisions.review'));
            foreach ($recipients as $user) {
                $count += $this->upsert($user, $fingerprint, [
                    'management_review_id' => $review->id,
                    'type' => 'management_review_due',
                    'severity' => 'medium',
                    'title' => 'Management Review akan berlangsung',
                    'message' => $review->reference.' · '.$review->title.' dijadwalkan '.$review->meeting_at->format('d M Y H:i').'.',
                    'action_url' => '/decisions',
                    'data' => ['reference' => $review->reference],
                ]);
            }
        }

        return $count;
    }

    private function autoEscalateCriticalSignals(CarbonInterface $asOf): int
    {
        $level1Minutes = max(1, (int) config('nadi.monitoring.critical_level1_minutes', 120));
        $level2Minutes = max($level1Minutes, (int) config('nadi.monitoring.critical_level2_minutes', 480));
        $count = 0;

        $signalIds = RiskSignal::query()
            ->where('severity', 'critical')
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->whereNull('acknowledged_at')
            ->pluck('id');

        foreach ($signalIds as $signalId) {
            $signal = RiskSignal::query()->whereKey($signalId)->lockForUpdate()->first();
            if (! $signal
                || $signal->severity !== 'critical'
                || in_array($signal->status, ['resolved', 'dismissed'], true)
                || $signal->acknowledged_at) {
                continue;
            }

            $ageMinutes = $signal->detected_at?->diffInMinutes($asOf, false) ?? 0;
            $desiredLevel = $ageMinutes >= $level2Minutes ? 2 : ($ageMinutes >= $level1Minutes ? 1 : 0);
            if ($desiredLevel <= (int) $signal->escalation_level) continue;

            $signal->update([
                'escalation_level' => $desiredLevel,
                'escalated_at' => $asOf,
                'escalated_by' => null,
                'auto_escalated_at' => $asOf,
            ]);
            ActionItem::query()->where('risk_signal_id', $signal->id)->whereNot('status', 'completed')->update([
                'escalation_level' => $desiredLevel,
                'escalated_at' => $asOf,
                'escalated_by' => null,
                'priority' => $desiredLevel >= 2 ? 'critical' : DB::raw("CASE WHEN priority = 'low' THEN 'medium' ELSE priority END"),
                'updated_at' => $asOf,
            ]);
            AuditLog::create([
                'user_id' => null,
                'action' => 'auto_escalate_risk_signal',
                'entity_type' => RiskSignal::class,
                'entity_id' => $signal->id,
                'changes' => ['escalation_level' => $desiredLevel, 'reason' => 'Critical signal belum diakui sesuai monitoring threshold.'],
                'ip_address' => null,
            ]);
            $this->notifyEscalation($signal->fresh(), $desiredLevel);
            $count++;
        }

        return $count;
    }

    private function notifyEscalation(RiskSignal $signal, int $level): void
    {
        $fingerprint = 'risk:'.$signal->id.':auto-escalation:'.$level.':'.optional($signal->detected_at)->timestamp;
        foreach ($this->recipientsForDepartment($signal->department_id, includeCompanyOversight: true) as $user) {
            $this->upsert($user, $fingerprint, [
                'risk_signal_id' => $signal->id,
                'type' => 'risk_escalated',
                'severity' => 'critical',
                'title' => 'Eskalasi level '.$level.' · '.$signal->title,
                'message' => 'Signal kritis belum diakui dan telah dieskalasi otomatis oleh monitor NADI.',
                'action_url' => '/decisions',
                'data' => ['escalation_level' => $level],
            ]);
        }
    }

    private function recipientsForDepartment(?int $departmentId, bool $includeCompanyOversight = false): Collection
    {
        return User::query()->where('is_active', true)->with(['department', 'permissionOverrides'])->get()
            ->filter(function (User $user) use ($departmentId, $includeCompanyOversight): bool {
                if (! $user->hasPermission('decisions.view')) return false;
                if ($user->role === 'director') return true;
                if ($user->department?->code === 'quality') return $includeCompanyOversight || $user->role === 'department_head';
                return $departmentId !== null && $user->department_id === $departmentId;
            })->values();
    }

    private function purgeOldNotifications(CarbonInterface $asOf): int
    {
        $days = max(30, (int) config('nadi.monitoring.notification_retention_days', 180));
        return UserNotification::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', $asOf->copy()->subDays($days))
            ->delete();
    }

    private function upsert(User $user, string $fingerprint, array $payload): int
    {
        $notification = UserNotification::query()->firstOrCreate(
            ['user_id' => $user->id, 'fingerprint' => $fingerprint],
            $payload
        );
        $isNew = $notification->wasRecentlyCreated;
        if (! $isNew) {
            $notification->fill($payload);
            if ($notification->isDirty()) {
                $notification->save();
            }
        }

        return $isNew ? 1 : 0;
    }
}
