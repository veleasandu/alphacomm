<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public auth routes
Route::controller(AuthController::class)->group(function () {
    Route::post('register', 'register');
    Route::post('login', 'login');
});

// Protected auth routes
Route::controller(AuthController::class)
    ->middleware('auth:api')
    ->group(function () {
        Route::post('logout', 'logout');
        Route::post('refresh', 'refresh');
    });

// Public webhook endpoint
Route::post('webhooks/payment', [TransactionController::class, 'handleWebhook']);

// Protected routes
Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {
    // Orders
    Route::controller(OrderController::class)->group(function () {
        Route::get('orders', 'index');
        Route::post('orders', 'store');
        Route::get('orders/{order}', 'show');
        Route::post('orders/{order}/pay', 'processPayment');
    });

    // Transactions
    Route::controller(TransactionController::class)->group(function () {
        Route::get('transactions/{transaction}', 'show');
    });
});
