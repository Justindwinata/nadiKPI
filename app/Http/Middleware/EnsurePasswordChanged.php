<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'message' => 'Kata sandi sementara harus diganti sebelum menggunakan modul operasional.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 423);
        }

        return $next($request);
    }
}
