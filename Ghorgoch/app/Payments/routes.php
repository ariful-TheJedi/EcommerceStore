<?php

use App\Auth\Middleware\AuthenticateAccount;
use App\Auth\Middleware\EnsureAuthFeatureEnabled;
use App\Payments\Controllers\CustomerPaymentController;
use Illuminate\Support\Facades\Route;

Route::get('customer/payments', [CustomerPaymentController::class, 'index'])
    ->middleware(['auth-session', EnsureAuthFeatureEnabled::class.':customer_enabled', AuthenticateAccount::class.':customer'])
    ->defaults('customer_self', true)->name('customer.payments.index');
Route::get('customers/{customer}/payments', [CustomerPaymentController::class, 'index'])
    ->middleware(['auth-session', AuthenticateAccount::class.':staff'])->whereNumber('customer')
    ->defaults('staff_permission', 'customers.view')->defaults('summary_permission', 'payments.view')->name('customers.payments.index');

Route::middleware('api')->prefix('payments')->group(function () {
    Route::get('/health', fn () => ['module' => 'payments']);
});
