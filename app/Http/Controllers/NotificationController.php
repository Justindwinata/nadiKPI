<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(10, (int) $request->integer('limit', 50)));
        $query = UserNotification::query()->where('user_id', $request->user()->id);
        $items = (clone $query)->latest('id')->limit($limit)->get();

        return response()->json([
            'summary' => [
                'unread' => (clone $query)->whereNull('read_at')->count(),
                'unacknowledged_critical' => (clone $query)->where('severity', 'critical')->whereNull('acknowledged_at')->count(),
            ],
            'notifications' => $items,
        ]);
    }

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        $this->authorizeOwner($request, $notification);
        if (! $notification->read_at) $notification->update(['read_at' => now()]);
        return response()->json(['notification' => $notification->fresh()]);
    }

    public function acknowledge(Request $request, UserNotification $notification): JsonResponse
    {
        $this->authorizeOwner($request, $notification);

        $notification = DB::transaction(function () use ($request, $notification): UserNotification {
            $locked = UserNotification::query()->whereKey($notification->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($request, $locked);

            if (! $locked->acknowledged_at) {
                $locked->update(['read_at' => $locked->read_at ?? now(), 'acknowledged_at' => now()]);
                AuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'acknowledge_notification',
                    'entity_type' => UserNotification::class,
                    'entity_id' => $locked->id,
                    'changes' => ['fingerprint' => $locked->fingerprint, 'type' => $locked->type],
                    'ip_address' => $request->ip(),
                ]);
            }

            return $locked->fresh();
        });

        return response()->json(['notification' => $notification]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $now = now();
        $count = UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => $now, 'updated_at' => $now]);
        return response()->json(['updated' => $count]);
    }

    public function stream(Request $request): StreamedResponse
    {
        $userId = $request->user()->id;
        $cursor = max(0, (int) $request->integer('cursor', 0), (int) $request->header('Last-Event-ID', 0));

        return response()->stream(function () use ($userId, $cursor): void {
            $lastId = $cursor;
            $deadline = microtime(true) + max(5, min(60, (int) config('nadi.monitoring.sse_stream_seconds', 20))); 
            echo "retry: 5000\n\n";
            @ob_flush(); @flush();

            while (microtime(true) < $deadline && ! connection_aborted()) {
                $items = UserNotification::query()->where('user_id', $userId)->where('id', '>', $lastId)->orderBy('id')->limit(20)->get();
                foreach ($items as $item) {
                    $lastId = $item->id;
                    echo 'id: '.$item->id."\n";
                    echo "event: notification\n";
                    echo 'data: '.json_encode($item->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                }
                echo "event: heartbeat\ndata: {\"cursor\":{$lastId}}\n\n";
                @ob_flush(); @flush();
                usleep(2_000_000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    private function authorizeOwner(Request $request, UserNotification $notification): void
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
    }
}
