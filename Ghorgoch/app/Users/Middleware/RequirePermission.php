<?php

namespace App\Users\Middleware;

use App\Users\Domain\Services\StaffPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless($request->user() && app(StaffPermissions::class)->allows($request->user(), $permission), 403);

        return $next($request);
    }
}
