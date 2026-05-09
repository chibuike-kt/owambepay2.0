<?php

use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/auth.php';

Route::middleware(['auth:sanctum'])->group(function () {
  Route::get('/user', [UserController::class, 'show']);

  // Wallet routes
  Route::prefix('wallets')->group(function () {
    Route::get('/',        [WalletController::class, 'index']);
    Route::post('/fund',   [WalletController::class, 'fund']);
    Route::get('/ledger',  [WalletController::class, 'ledger']);
  });
});

Route::get('/health', function () {
  return response()->json(['status' => 'ok', 'service' => 'OwambePay API']);
});
