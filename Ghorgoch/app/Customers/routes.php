<?php

use App\Auth\Middleware\EnsureAuthFeatureEnabled;
use App\Customers\Controllers\RegistrationController;
use App\Customers\Middleware\EnsureRegistrationEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', EnsureAuthFeatureEnabled::class.':customer_enabled'])->prefix('customers')->group(function () {
    Route::post('/register', [RegistrationController::class, 'store'])
        ->middleware(['auth-session', EnsureRegistrationEnabled::class, 'throttle:customer-registration'])
        ->name('customers.register');

    Route::get('/health', fn () => ['module' => 'customers']);
});
