<?php

use App\Http\Controllers\Auth\UserController;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/auth.php';

Route::middleware(['auth:sanctum'])->group(function () {
  Route::get('/user', [UserController::class, 'show']);
});

Route::get('/health', function () {
  return response()->json(['status' => 'ok', 'service' => 'OwambePay API']);
});
