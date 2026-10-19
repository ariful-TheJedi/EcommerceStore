<?php

namespace App\Auth\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

class RequireCsrfToken extends PreventRequestForgery
{
    /**
     * Auth's cookie clients must supply a session token even for same-origin requests.
     */
    protected function hasValidOrigin($request): bool
    {
        return false;
    }
}
