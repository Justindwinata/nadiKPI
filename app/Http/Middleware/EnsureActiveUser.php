<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active) {
            return response()->json([
                'message' => 'Akun tidak aktif. Hubungi administrator.',
                'code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        return $next($request);
    }
}
