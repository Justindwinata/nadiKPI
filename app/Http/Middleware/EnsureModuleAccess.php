<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($request->user()?->is_active && $request->user()->canAccess($module), 403, 'Anda tidak memiliki akses ke modul ini.');

        return $next($request);
    }
}
