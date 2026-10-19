<?php

use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('orders')->group(function () {
    Route::get('/health', fn () => ['module' => 'orders']);
});

Route::middleware('api')->prefix('cart')->group(function () {
    Route::get('/health', fn () => ['module' => 'cart']);
});
