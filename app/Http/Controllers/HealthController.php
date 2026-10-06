<?php

namespace App\Http\Controllers;

use App\Services\ReleaseReadinessService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function ready(ReleaseReadinessService $readiness): JsonResponse
    {
        $result = $readiness->evaluate(
            production: app()->environment('production'),
            includeDatabase: true,
            includeBuild: true,
        );

        return response()->json([
            'status' => $result['ready'] ? 'ready' : 'not_ready',
            'checks' => collect($result['checks'])->map(fn (array $check): array => [
                'name' => $check['name'],
                'status' => $check['status'],
            ])->values(),
            'checked_at' => now()->toIso8601String(),
        ], $result['ready'] ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
