<?php

use App\Auth\Middleware\AuthenticateAccount;
use App\Users\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::prefix('users')->name('users.')->middleware(['auth-session', AuthenticateAccount::class.':staff'])->group(function (): void {
    Route::get('/me', [StaffController::class, 'me'])->name('me');
    Route::patch('/me', [StaffController::class, 'updateMe'])->middleware('throttle:auth-sensitive')->name('me.update');
    Route::get('/capabilities', [StaffController::class, 'capabilities'])->name('capabilities');
    Route::get('/', [StaffController::class, 'index'])->name('index');
    Route::post('/', [StaffController::class, 'store'])->middleware('throttle:auth-sensitive')->name('store');
    Route::get('/{staff}', [StaffController::class, 'show'])->whereNumber('staff')->name('show');
    Route::patch('/{staff}', [StaffController::class, 'update'])->whereNumber('staff')->middleware('throttle:auth-sensitive')->name('update');
    Route::post('/{staff}/disable', [StaffController::class, 'disable'])->whereNumber('staff')->middleware('throttle:auth-sensitive')->name('disable');
    Route::post('/{staff}/reactivate', [StaffController::class, 'reactivate'])->whereNumber('staff')->middleware('throttle:auth-sensitive')->name('reactivate');
    Route::get('/{staff}/activity', [StaffController::class, 'activity'])->whereNumber('staff')->name('activity');
});
