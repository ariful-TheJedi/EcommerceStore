<?php

namespace App\Users\Middleware;

use App\Auth\Middleware\AuthenticateAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

class EnforceStaffAccess
{
    public function __construct(private readonly Router $router, private readonly Pipeline $pipeline) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(config('staff.protected_paths')) || $this->isPublicRoute($request)) {
            return $next($request);
        }

        $permission = $request->route()->defaults['staff_permission'] ?? 'undeclared.permission';
        $middleware = $this->router->resolveMiddleware([
            'auth-session',
            AuthenticateAccount::class.':staff',
            RequirePermission::class.':'.$permission,
        ]);

        return $this->pipeline->send($request)->through($middleware)->then($next);
    }

    private function isPublicRoute(Request $request): bool
    {
        return in_array($request->method().' '.$request->path(), config('staff.public_routes'), true);
    }
}
