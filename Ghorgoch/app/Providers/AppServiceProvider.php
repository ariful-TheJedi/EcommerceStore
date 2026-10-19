<?php

namespace App\Providers;

use App\Orders\Models\Order;
use App\Payments\Models\Payment;
use App\Users\Domain\Services\StaffAttribution;
use App\Users\Domain\Services\StaffPermissions;
use App\Users\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(StaffAttribution $attribution): void
    {
        Order::saving($attribution->savingOrder(...));
        Payment::saving($attribution->savingPayment(...));
        foreach (config('staff.permissions') as $permission) {
            Gate::define($permission, function (User $user) use ($permission): bool {
                $current = $user->fresh();

                return $current !== null && app(StaffPermissions::class)->allows($current, $permission);
            });
        }
        foreach (['auth-login' => 5, 'auth-recovery' => 3, 'auth-reset' => 5] as $name => $attempts) {
            RateLimiter::for($name, function (Request $request) use ($attempts): array {
                $email = $request->input('email');
                $identity = hash('sha256', ($request->route()->defaults['account_type'] ?? '').'|'.(is_string($email) ? strtolower(trim($email)) : 'invalid').'|'.$request->ip());

                return [Limit::perMinute($attempts)->by('identity:'.$identity), Limit::perMinute(30)->by('ip:'.$request->ip())];
            });
        }
        RateLimiter::for('customer-registration', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-resend', fn (Request $request): Limit => Limit::perMinute(1)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('auth-sensitive', fn (Request $request): Limit => Limit::perMinute(10)->by((string) ($request->user()?->id ?? $request->ip())));

        foreach (glob(app_path('Modules/*/routes.php')) as $routesFile) {
            Route::prefix('api')->group(function () use ($routesFile): void {
                require $routesFile;
            });
        }
    }
}
