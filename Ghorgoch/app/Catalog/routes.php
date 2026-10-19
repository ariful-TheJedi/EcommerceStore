<?php

use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('products')->group(function () {
    Route::get('/health', fn () => ['module' => 'products']);
});

Route::middleware('api')->prefix('categories')->group(function () {
    Route::get('/health', fn () => ['module' => 'categories']);
});

Route::middleware('api')->prefix('inventory')->group(function () {
    Route::get('/health', fn () => ['module' => 'inventory']);
});
