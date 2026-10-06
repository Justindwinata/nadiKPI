<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\AuthenticationEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function demoAccess(): JsonResponse
    {
        abort_unless((bool) config('nadi.demo_mode'), 404);
        $demoPassword = trim((string) config('nadi.demo_password'));
        abort_if($demoPassword === '', 503, 'Demo mode belum dikonfigurasi.');

        $accounts = User::query()
            ->where('is_active', true)
            ->where('email', 'like', '%@demo.test')
            ->orderByRaw("CASE role WHEN 'director' THEN 1 WHEN 'department_head' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get(['name', 'email', 'position'])
            ->map(fn (User $user) => [
                'label' => $user->position ?: $user->name,
                'email' => $user->email,
            ])
            ->values();

        return response()->json([
            'accounts' => $accounts,
            'password' => $demoPassword,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $key = $this->throttleKey($email, $request->ip());
        $ipKey = $this->ipThrottleKey($request->ip());

        if (RateLimiter::tooManyAttempts($key, (int) config('nadi.security.login_max_attempts')) || RateLimiter::tooManyAttempts($ipKey, (int) config('nadi.security.login_ip_max_attempts'))) {
            $retryAfter = max(RateLimiter::availableIn($key), RateLimiter::availableIn($ipKey));
            $this->authenticationEvent($request, null, $email, 'login_throttled', false);

            return response()->json([
                'message' => "Terlalu banyak percobaan masuk. Coba lagi dalam {$retryAfter} detik.",
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $credentials['password']], false)) {
            RateLimiter::hit($key, (int) config('nadi.security.login_decay_seconds'));
            RateLimiter::hit($ipKey, (int) config('nadi.security.login_decay_seconds'));
            $this->authenticationEvent($request, null, $email, 'login_failed', false);

            return response()->json(['message' => 'Email atau kata sandi tidak sesuai.'], 422);
        }

        $user = $request->user();
        if (! $user->is_active) {
            $this->authenticationEvent($request, $user->id, $email, 'login_inactive_account', false);
            Auth::logout();
            RateLimiter::hit($key, (int) config('nadi.security.login_decay_seconds'));
            RateLimiter::hit($ipKey, (int) config('nadi.security.login_decay_seconds'));

            return response()->json(['message' => 'Email atau kata sandi tidak sesuai, atau akun tidak aktif.'], 422);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();
        $this->authenticationEvent($request, $user->id, $email, 'login_success', true);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        $user = $request->user();

        $user = DB::transaction(function () use ($user, $data, $request): User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // The FormRequest-style current_password validation happens before the database lock.
            // Re-check it here so an administrator reset cannot be overwritten by a stale session.
            if (! Hash::check($data['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini sudah berubah. Muat ulang sesi dan coba lagi.']);
            }
            if (Hash::check($data['password'], $locked->password)) {
                throw ValidationException::withMessages(['password' => 'Kata sandi baru harus berbeda dari kata sandi saat ini.']);
            }

            $locked->forceFill([
                'password' => $data['password'],
                'must_change_password' => false,
                'password_changed_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')
                ->where('user_id', $locked->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();

            AuditLog::create([
                'user_id' => $locked->id,
                'action' => 'password_change',
                'entity_type' => User::class,
                'entity_id' => $locked->id,
                'changes' => ['must_change_password' => false],
                'ip_address' => $request->ip(),
            ]);

            return $locked->fresh();
        });

        $request->session()->regenerate();
        $this->authenticationEvent($request, $user->id, $user->email, 'password_changed', true);

        return response()->json([
            'message' => 'Kata sandi berhasil diperbarui.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user()) {
            $this->authenticationEvent($request, $request->user()->id, $request->user()->email, 'logout', true);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesi telah diakhiri.']);
    }

    private function userPayload($user): array
    {
        $user->loadMissing(['department', 'permissionOverrides']);
        $payload = $user->toArray();
        $payload['permissions'] = $user->effectivePermissions();

        return $payload;
    }

    private function throttleKey(string $email, ?string $ip): string
    {
        return 'login:'.Str::transliterate($email).'|'.($ip ?: 'unknown');
    }

    private function ipThrottleKey(?string $ip): string
    {
        return 'login-ip:'.($ip ?: 'unknown');
    }

    private function authenticationEvent(Request $request, ?int $userId, ?string $email, string $event, bool $successful): void
    {
        AuthenticationEvent::create([
            'user_id' => $userId,
            'email' => $email,
            'event' => $event,
            'successful' => $successful,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'occurred_at' => now(),
        ]);
    }
}
