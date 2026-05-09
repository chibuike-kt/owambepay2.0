<?php

namespace App\Services;

use App\Events\MoneySprayedEvent;
use App\Models\Event;
use App\Models\Spray;
use App\Models\SpraySession;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SprayService
{
  public function __construct(private WalletService $walletService) {}

  /**
   * Create a guest wallet + spray session when guest joins.
   * Guest wallet is funded immediately with the amount they choose.
   */
  public function joinEvent(
    Event $event,
    string $guestName,
    float $fundAmount
  ): array {
    if (!$event->isActive()) {
      throw new \Exception('This event has ended.');
    }

    return DB::transaction(function () use ($event, $guestName, $fundAmount) {
      // Create ephemeral guest wallet
      $guestWallet = Wallet::create([
        'user_id'           => $event->host_user_id, // owned by host for simplicity
        'currency'          => 'NGN',
        'balance'           => 0,
        'available_balance' => 0,
        'status'            => 'active',
        'metadata'          => [
          'type'       => 'guest',
          'guest_name' => $guestName,
          'event_id'   => $event->id,
        ],
      ]);

      // Fund the guest wallet (2% fee applies)
      $this->walletService->fundWallet(
        wallet: $guestWallet,
        grossAmount: $fundAmount,
        provider: 'devwallet',
      );

      // Create spray session
      $session = SpraySession::create([
        'event_id'    => $event->id,
        'guest_name'  => $guestName,
        'guest_token' => Str::uuid(),
        'currency'    => 'NGN',
        'status'      => 'active',
        'metadata'    => ['guest_wallet_id' => $guestWallet->id],
      ]);

      return [
        'session'      => $session,
        'guest_wallet' => $guestWallet,
      ];
    });
  }

  /**
   * Execute a spray.
   * Atomically: debit guest wallet → credit escrow → record spray → broadcast.
   */
  public function spray(
    Event $event,
    SpraySession $session,
    Wallet $guestWallet,
    float $amount,
    string $noteType = '100',
    string $message = ''
  ): Spray {
    if (!$event->isActive()) {
      throw new \Exception('This event has ended.');
    }

    if ($guestWallet->available_balance < $amount) {
      throw new \Exception('Insufficient balance.');
    }

    if ($amount < 1) {
      throw new \Exception('Minimum spray amount is ₦1.');
    }

    return DB::transaction(function () use (
      $event,
      $session,
      $guestWallet,
      $amount,
      $noteType,
      $message
    ) {
      // Lock both wallets
      $guestWallet   = Wallet::lockForUpdate()->find($guestWallet->id);
      $escrowWallet  = Wallet::lockForUpdate()->find($event->escrow_wallet_id);

      // Re-check balance inside transaction
      if ($guestWallet->available_balance < $amount) {
        throw new \Exception('Insufficient balance.');
      }

      // Create spray transaction
      $transaction = Transaction::create([
        'reference'       => Transaction::generateReference('SPR'),
        'type'            => 'spray',
        'status'          => 'success',
        'amount'          => $amount,
        'currency'        => 'NGN',
        'wallet_id'       => $guestWallet->id,
        'narration'       => "{$session->guest_name} sprayed ₦{$amount} at {$event->title}",
        'idempotency_key' => Str::uuid(),
      ]);

      // Debit guest wallet
      $this->walletService->debitWallet(
        wallet: $guestWallet,
        amount: $amount,
        transaction: $transaction,
        description: "Spray at {$event->title}",
      );

      // Credit escrow wallet
      $this->walletService->creditWallet(
        wallet: $escrowWallet,
        amount: $amount,
        transaction: $transaction,
        description: "Spray received from {$session->guest_name}",
      );

      // Record spray
      $spray = Spray::create([
        'event_id'        => $event->id,
        'spray_session_id' => $session->id,
        'guest_wallet_id' => $guestWallet->id,
        'escrow_wallet_id' => $escrowWallet->id,
        'transaction_id'  => $transaction->id,
        'guest_name'      => $session->guest_name,
        'amount'          => $amount,
        'currency'        => 'NGN',
        'note_type'       => $noteType,
        'message'         => $message,
      ]);

      // Update session total
      $session->increment('total_sprayed', $amount);

      // Broadcast to all connected clients in this event channel
      broadcast(new MoneySprayedEvent($spray->load('event')));

      return $spray;
    });
  }
}
