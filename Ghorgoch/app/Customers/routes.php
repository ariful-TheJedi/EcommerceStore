<?php

use App\Auth\Middleware\AuthenticateAccount;
use App\Auth\Middleware\EnsureAuthFeatureEnabled;
use App\Customers\Controllers\AddressController;
use App\Customers\Controllers\CustomerController;
use App\Customers\Controllers\RegistrationController;
use App\Customers\Middleware\EnsureRegistrationEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', EnsureAuthFeatureEnabled::class.':customer_enabled'])->prefix('customers')->group(function () {
    Route::post('/register', [RegistrationController::class, 'store'])
        ->middleware(['auth-session', EnsureRegistrationEnabled::class, 'throttle:customer-registration'])
        ->name('customers.register');

    Route::get('/health', fn () => ['module' => 'customers']);
});

Route::prefix('customer')->name('customer.')->middleware(['auth-session', EnsureAuthFeatureEnabled::class.':customer_enabled', AuthenticateAccount::class.':customer'])->group(function (): void {
    Route::get('/profile', [CustomerController::class, 'me'])->defaults('customer_self', true)->name('profile');
    Route::patch('/profile', [CustomerController::class, 'updateMe'])->defaults('customer_self', true)->name('profile.update');
    Route::post('/close', [CustomerController::class, 'close'])->defaults('customer_self', true)->middleware('throttle:auth-sensitive')->name('close');
    foreach (['get' => 'index', 'post' => 'store'] as $method => $action) {
        Route::$method('/addresses', [AddressController::class, $action])->defaults('customer_self', true)->name('addresses.'.$action);
    }
    foreach (['get' => 'show', 'patch' => 'update', 'delete' => 'destroy'] as $method => $action) {
        Route::$method('/addresses/{address}', [AddressController::class, $action])->whereNumber('address')->defaults('customer_self', true)->name('addresses.'.$action);
    }
});

Route::prefix('customers')->name('customers.')->middleware(['auth-session', AuthenticateAccount::class.':staff'])->group(function (): void {
    Route::get('/', [CustomerController::class, 'index'])->defaults('staff_permission', 'customers.view')->name('index');
    Route::post('/', [CustomerController::class, 'store'])->defaults('staff_permission', 'customers.manage')->name('store');
    Route::get('/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->defaults('staff_permission', 'customers.view')->name('show');
    Route::patch('/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->defaults('staff_permission', 'customers.manage')->name('update');
    foreach (['block', 'unblock', 'close'] as $action) {
        Route::post('/{customer}/'.$action, [CustomerController::class, $action])->whereNumber('customer')->defaults('staff_permission', 'customers.manage')->name($action);
    }
    Route::get('/{customer}/status-history', [CustomerController::class, 'statusHistory'])->whereNumber('customer')->defaults('staff_permission', 'customers.view')->name('status-history');
    foreach (['get' => 'notes', 'post' => 'storeNote'] as $method => $action) {
        Route::$method('/{customer}/notes', [CustomerController::class, $action])->whereNumber('customer')->defaults('staff_permission', 'customers.notes')->name($action);
    }
    foreach (['get' => 'index', 'post' => 'store'] as $method => $action) {
        Route::$method('/{customer}/addresses', [AddressController::class, $action])->whereNumber('customer')->defaults('staff_permission', $method === 'get' ? 'customers.view' : 'customers.manage')->name('addresses.'.$action);
    }
    foreach (['get' => 'show', 'patch' => 'update', 'delete' => 'destroy'] as $method => $action) {
        Route::$method('/{customer}/addresses/{address}', [AddressController::class, $action])->whereNumber('customer')->whereNumber('address')->defaults('staff_permission', $method === 'get' ? 'customers.view' : 'customers.manage')->name('addresses.'.$action);
    }
});
