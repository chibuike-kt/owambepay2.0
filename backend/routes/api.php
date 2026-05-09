<?php

use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\SprayController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/auth.php';

Route::middleware(['auth:sanctum'])->group(function () {
  Route::get('/user', [UserController::class, 'show']);

  Route::prefix('wallets')->group(function () {
    Route::get('/',            [WalletController::class, 'index']);
    Route::post('/initialize', [WalletController::class, 'initialize']); // ← NEW
    Route::post('/verify',     [WalletController::class, 'verify']);      // ← NEW
    Route::post('/fund',       [WalletController::class, 'fund']);
    Route::get('/ledger',      [WalletController::class, 'ledger']);
  });

  Route::prefix('events')->group(function () {
    Route::get('/',            [EventController::class, 'index']);
    Route::post('/',           [EventController::class, 'store']);
    Route::post('/{slug}/end', [EventController::class, 'end']);
  });
});

// Public routes — no auth
Route::get('/events/{slug}',                   [EventController::class, 'show']);
Route::post('/events/{slug}/initialize-guest', [SprayController::class, 'initializeGuest']); // ← NEW
Route::post('/events/{slug}/verify-guest',     [SprayController::class, 'verifyGuest']);      // ← NEW
Route::post('/events/{slug}/join',             [SprayController::class, 'join']);
Route::post('/events/{slug}/spray',            [SprayController::class, 'spray']);
Route::get('/events/{slug}/sprays',            [SprayController::class, 'index']);

Route::get('/health', function () {
  return response()->json(['status' => 'ok', 'service' => 'OwambePay API']);
});
