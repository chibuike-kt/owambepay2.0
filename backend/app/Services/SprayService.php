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
   * Create guest wallet + session after a verified DevWallet payment.
   * Reference used for idempotency — safe to call twice.
   */
  public function joinEventWithPayment(
    Event  $event,
    string $guestName,
    float  $grossAmount,
    string $reference,
  ): array {
    if (!$event->isActive()) {
      throw new \Exception('This event has ended.');
    }

    // Idempotency — return existing session if reference already used
    $existingSession = SpraySession::where('metadata->payment_reference', $reference)->first();
    if ($existingSession) {
      $guestWallet = Wallet::find($existingSession->metadata['guest_wallet_id']);
      return ['session' => $existingSession, 'guest_wallet' => $guestWallet];
    }

    return DB::transaction(function () use ($event, $guestName, $grossAmount, $reference) {
      // Create ephemeral guest wallet
      $guestWallet = Wallet::create([
        'user_id'           => $event->host_user_id,
        'type'              => 'guest',
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

      // Credit wallet after verified payment (2% fee deducted)
      $this->walletService->creditFromVerifiedPayment(
        wallet: $guestWallet,
        grossAmount: $grossAmount,
        reference: 'GST-' . $reference,
        provider: 'devwallet',
      );

      $guestWallet->refresh();

      // Create spray session
      $session = SpraySession::create([
        'event_id'   => $event->id,
        'guest_name' => $guestName,
        'guest_token' => Str::uuid(),
        'currency'   => 'NGN',
        'status'     => 'active',
        'metadata'   => [
          'guest_wallet_id'    => $guestWallet->id,
          'payment_reference'  => $reference,
        ],
      ]);

      return ['session' => $session, 'guest_wallet' => $guestWallet];
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
