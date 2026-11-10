<?php

namespace App\Orders\Middleware;

use App\Auth\Middleware\AuthenticateAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IdentifyShopper
{
    public function __construct(private readonly AuthenticateAccount $authenticate) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('customer')->check()) {
            return $this->authenticate->handle($request, $next, 'customer');
        }
        if (Auth::guard('staff')->check()) {
            $storefrontCheckout = $request->routeIs('orders.cart.sync')
                || ($request->routeIs('orders.checkout.review', 'orders.checkout.store') && $request->has('delivery_zone'));
            abort_unless($storefrontCheckout, 403);

            return $this->authenticate->handle($request, function (Request $request) use ($next): Response {
                Auth::shouldUse('customer');
                try {
                    return $next($request);
                } finally {
                    Auth::shouldUse('staff');
                }
            }, 'staff');
        }
        Auth::shouldUse('customer');

        return $next($request);
    }
}
