<?php

use App\Auth\Middleware\AuthenticateAccount;
use App\Auth\Middleware\EnsureAuthFeatureEnabled;
use App\Orders\Controllers\CartController;
use App\Orders\Controllers\CheckoutController;
use App\Orders\Controllers\CustomerOrderController;
use App\Orders\Controllers\DeliveryChargeController;
use App\Orders\Controllers\OrderController;
use App\Orders\Middleware\IdentifyShopper;
use Illuminate\Support\Facades\Route;

Route::get('customer/orders', [CustomerOrderController::class, 'index'])
    ->middleware(['auth-session', EnsureAuthFeatureEnabled::class.':customer_enabled', AuthenticateAccount::class.':customer'])
    ->defaults('customer_self', true)->name('customer.orders.index');
Route::get('customers/{customer}/orders', [CustomerOrderController::class, 'index'])
    ->middleware(['auth-session', AuthenticateAccount::class.':staff'])->whereNumber('customer')
    ->defaults('staff_permission', 'customers.view')->defaults('summary_permission', 'orders.view')->name('customers.orders.index');

Route::middleware('api')->prefix('orders')->group(function () {
    Route::get('/health', fn () => ['module' => 'orders']);
});

Route::middleware('api')->prefix('cart')->group(function () {
    Route::get('/health', fn () => ['module' => 'cart']);
});

Route::middleware(['auth-session', EnsureAuthFeatureEnabled::class.':customer_enabled', IdentifyShopper::class])->group(function (): void {
    Route::get('/cart', [CartController::class, 'show'])->name('orders.cart.show');
    Route::post('/cart/items', [CartController::class, 'store'])->name('orders.cart.items.store');
    Route::patch('/cart/items/{variant}', [CartController::class, 'update'])->whereNumber('variant')->name('orders.cart.items.update');
    Route::delete('/cart/items/{variant}', [CartController::class, 'destroy'])->whereNumber('variant')->name('orders.cart.items.destroy');
    Route::post('/cart/merge', [CartController::class, 'merge'])->name('orders.cart.merge');
    Route::post('/cart/sync', [CartController::class, 'sync'])->name('orders.cart.sync');
    Route::post('/checkout/review', [CheckoutController::class, 'review'])->middleware('throttle:checkout')->name('orders.checkout.review');
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout')->name('orders.checkout.store');
});

Route::get('/checkout/delivery-charges', [DeliveryChargeController::class, 'publicShow'])->name('orders.delivery-charges.public');

Route::prefix('customer/orders')->middleware(['auth-session', EnsureAuthFeatureEnabled::class.':customer_enabled', AuthenticateAccount::class.':customer'])->group(function (): void {
    Route::get('/{order}', [OrderController::class, 'show'])->whereNumber('order')->defaults('order_audience', 'customer')->name('orders.customer.show');
    Route::post('/{order}/cancel', [OrderController::class, 'cancel'])->whereNumber('order')->defaults('order_audience', 'customer')->name('orders.customer.cancel');
});

Route::prefix('guest/orders')->middleware(['auth-session', 'throttle:guest-orders'])->group(function (): void {
    Route::get('/{number}', [OrderController::class, 'show'])->defaults('order_audience', 'guest')->name('orders.guest.show');
    Route::post('/{number}/cancel', [OrderController::class, 'cancel'])->defaults('order_audience', 'guest')->name('orders.guest.cancel');
});

Route::prefix('orders')->group(function (): void {
    Route::get('/delivery-charges', [DeliveryChargeController::class, 'show'])->defaults('staff_permission', 'orders.view')->name('orders.delivery-charges.show');
    Route::patch('/delivery-charges', [DeliveryChargeController::class, 'update'])->defaults('staff_permission', 'orders.process')->name('orders.delivery-charges.update');
    Route::get('/', [OrderController::class, 'index'])->defaults('order_audience', 'staff')->defaults('staff_permission', 'orders.view')->name('orders.index');
    Route::get('/report', [OrderController::class, 'report'])->defaults('order_audience', 'staff')->defaults('staff_permission', 'orders.view')->name('orders.report');
    Route::post('/', [OrderController::class, 'store'])->defaults('order_audience', 'staff')->defaults('staff_permission', 'orders.process')->name('orders.store');
    Route::post('/bulk-delete', [OrderController::class, 'bulkDestroy'])->defaults('order_audience', 'staff')->defaults('staff_permission', 'orders.process')->name('orders.bulk-destroy');
    Route::delete('/{order}', [OrderController::class, 'destroy'])->whereNumber('order')->defaults('order_audience', 'staff')->defaults('staff_permission', 'orders.process')->name('orders.destroy');
    Route::get('/{order}', [OrderController::class, 'show'])->whereNumber('order')->defaults('order_audience', 'staff')->defaults('staff_permission', 'orders.view')->name('orders.show');
    foreach (['status' => 'transition', 'delivery' => 'delivery', 'delivery-address' => 'address', 'finances' => 'finances'] as $path => $action) {
        Route::patch('/{order}/'.$path, [OrderController::class, $action])->whereNumber('order')->defaults('order_audience', 'staff')->defaults('staff_permission', 'orders.process')->name('orders.'.$action);
    }
    Route::post('/{order}/notes', [OrderController::class, 'note'])->whereNumber('order')->defaults('order_audience', 'staff')->defaults('staff_permission', 'orders.process')->name('orders.notes.store');
});
