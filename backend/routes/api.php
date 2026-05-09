<?php

use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/auth.php';

Route::middleware(['auth:sanctum'])->group(function () {
  Route::get('/user', [UserController::class, 'show']);

  // Wallet routes
  Route::prefix('wallets')->group(function () {
    Route::get('/',       [WalletController::class, 'index']);
    Route::post('/fund',  [WalletController::class, 'fund']);
    Route::get('/ledger', [WalletController::class, 'ledger']);
  });

  // Event routes (authenticated)
  Route::prefix('events')->group(function () {
    Route::get('/',            [EventController::class, 'index']);
    Route::post('/',           [EventController::class, 'store']);
    Route::post('/{slug}/end', [EventController::class, 'end']);
  });
});

// Public event route — no auth, guests use this
Route::get('/events/{slug}', [EventController::class, 'show']);

Route::get('/health', function () {
  return response()->json(['status' => 'ok', 'service' => 'OwambePay API']);
});
