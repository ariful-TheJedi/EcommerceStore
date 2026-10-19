<?php

namespace App\Auth\Middleware;

use App\Auth\Domain\Services\AuthService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAccount implements AuthenticatesRequests
{
    public function __construct(private readonly AuthService $service) {}

    public function handle(Request $request, Closure $next, string $accountType): Response
    {
        Auth::shouldUse($accountType);
        $user = Auth::guard($accountType)->user()?->fresh();
        if (! $user) {
            throw new AuthenticationException;
        }

        $fingerprint = $request->session()->get('auth_fingerprint');
        $startedAt = $request->session()->get('auth_started_at', 0);
        if (! $user->is_active || ! $this->service->matchesType($user, $accountType)
            || ! is_string($fingerprint) || ! hash_equals($this->service->fingerprint($user), $fingerprint)
            || $startedAt <= now()->subMinutes(config('auth.session_max_minutes'))->timestamp) {
            if (! $user->is_active) {
                $this->service->revokeSessions($user);
            }
            Auth::guard($accountType)->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw new AuthenticationException;
        }

        Auth::guard($accountType)->setUser($user);

        return $next($request);
    }
}
