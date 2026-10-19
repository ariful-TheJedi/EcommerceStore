<?php

use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('payments')->group(function () {
    Route::get('/health', fn () => ['module' => 'payments']);
});
