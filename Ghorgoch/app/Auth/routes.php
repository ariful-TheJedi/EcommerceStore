<?php

use App\Auth\Controllers\AdminUserController;
use App\Auth\Controllers\AuthController;
use App\Auth\Middleware\AuthenticateAccount;
use App\Auth\Middleware\EnsureAuthFeatureEnabled;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::get('/health', fn (): array => ['module' => 'auth'])->name('health');

    Route::middleware('auth-session')->group(function (): void {
        Route::get('/csrf-token', [AuthController::class, 'csrf'])->name('csrf');

        Route::post('/admin/users', [AdminUserController::class, 'store'])
            ->middleware([AuthenticateAccount::class.':staff', 'throttle:auth-sensitive'])
            ->name('admin.users.store');

        foreach (['customer', 'staff'] as $accountType) {
            Route::middleware($accountType === 'customer' ? [EnsureAuthFeatureEnabled::class.':customer_enabled'] : [])->prefix($accountType)->name($accountType.'.')->group(function () use ($accountType): void {
                foreach ([
                    'login' => ['login', 'auth-login'],
                    'forgot-password' => ['forgotPassword', 'auth-recovery'],
                    'reset-password' => ['resetPassword', 'auth-reset'],
                ] as $path => [$action, $limiter]) {
                    Route::post($path, [AuthController::class, $action])
                        ->defaults('account_type', $accountType)
                        ->middleware($action === 'login' ? [] : [EnsureAuthFeatureEnabled::class.':email_enabled'])
                        ->middleware('throttle:'.$limiter)->name($path);
                }

                Route::middleware(AuthenticateAccount::class.':'.$accountType)->group(function () use ($accountType): void {
                    Route::get('/me', [AuthController::class, 'me'])->defaults('account_type', $accountType)->name('me');

                    foreach ([
                        'logout' => 'logout',
                        'logout-all' => 'logoutAll',
                        'password' => 'changePassword',
                        'email' => 'changeEmail',
                        'email/verify' => 'verifyEmail',
                        'email/resend' => 'resendVerification',
                    ] as $path => $action) {
                        Route::post($path, [AuthController::class, $action])
                            ->defaults('account_type', $accountType)
                            ->middleware(in_array($action, ['changeEmail', 'verifyEmail', 'resendVerification'], true) ? [EnsureAuthFeatureEnabled::class.':email_enabled'] : [])
                            ->middleware('throttle:'.($action === 'resendVerification' ? 'auth-resend' : 'auth-sensitive'))
                            ->name(str_replace('/', '.', $path));
                    }
                });
            });
        }
    });
});
