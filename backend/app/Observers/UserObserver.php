<?php

namespace App\Observers;

use App\Models\User;
use App\Services\WalletService;

class UserObserver
{
  public function __construct(private WalletService $walletService) {}

  public function created(User $user): void
  {
    // Create NGN wallet for every new host
    $this->walletService->createWallet($user, 'NGN');
  }
}
