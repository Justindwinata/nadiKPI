<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(
            $request->user()?->is_active && $request->user()->hasPermission($permission),
            403,
            'Anda tidak memiliki izin untuk melakukan tindakan ini.'
        );

        return $next($request);
    }
}
