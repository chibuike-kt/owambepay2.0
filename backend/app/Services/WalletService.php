<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
  const FUNDING_FEE_RATE    = 0.02; // 2%
  const WITHDRAWAL_FEE_RATE = 0.02; // 2%

  /**
   * Create a wallet for a user.
   * Called automatically when a host registers.
   */
  public function createWallet(User $user, string $currency = 'NGN'): Wallet
  {
    return Wallet::firstOrCreate(
      ['user_id' => $user->id, 'currency' => $currency],
      ['balance' => 0, 'available_balance' => 0, 'status' => 'active']
    );
  }

  /**
   * Fund a wallet.
   * Deducts 2% fee. Credits net amount to wallet.
   * All steps are atomic.
   */
  public function fundWallet(
    Wallet $wallet,
    float $grossAmount,
    string $provider = 'devwallet',
    string $providerReference = '',
    string $idempotencyKey = ''
  ): Transaction {

    if (!$wallet->isActive()) {
      throw new \Exception('Wallet is not active.');
    }

    if ($grossAmount <= 0) {
      throw new \Exception('Amount must be greater than zero.');
    }

    // Idempotency check — return existing transaction if key already used
    if ($idempotencyKey) {
      $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
      if ($existing) {
        return $existing;
      }
    }

    $feeAmount = round($grossAmount * self::FUNDING_FEE_RATE, 4);
    $netAmount = round($grossAmount - $feeAmount, 4);

    return DB::transaction(function () use (
      $wallet,
      $grossAmount,
      $netAmount,
      $feeAmount,
      $provider,
      $providerReference,
      $idempotencyKey
    ) {
      // Lock wallet row to prevent concurrent balance updates
      $wallet = Wallet::lockForUpdate()->find($wallet->id);

      // Create the main funding transaction
      $transaction = Transaction::create([
        'reference'       => Transaction::generateReference('FND'),
        'type'            => 'wallet_funding',
        'status'          => 'success',
        'amount'          => $netAmount,
        'currency'        => $wallet->currency,
        'wallet_id'       => $wallet->id,
        'provider'        => $provider,
        'narration'       => "Wallet funded via {$provider}",
        'idempotency_key' => $idempotencyKey ?: Str::uuid(),
        'metadata'        => [
          'gross_amount'        => $grossAmount,
          'fee_amount'          => $feeAmount,
          'provider_reference'  => $providerReference,
        ],
      ]);

      // Write ledger entry for the net credit
      $this->writeLedgerEntry(
        wallet: $wallet,
        transaction: $transaction,
        entryType: 'credit',
        amount: $netAmount,
        description: "Funding credit — gross ₦{$grossAmount}, fee ₦{$feeAmount}",
      );

      // Write ledger entry for the fee debit (goes to platform)
      $feeTransaction = Transaction::create([
        'reference'  => Transaction::generateReference('FEE'),
        'type'       => 'fee',
        'status'     => 'success',
        'amount'     => $feeAmount,
        'currency'   => $wallet->currency,
        'wallet_id'  => $wallet->id,
        'narration'  => 'Funding fee (2%)',
        'metadata'   => ['parent_transaction' => $transaction->id],
      ]);

      $this->writeLedgerEntry(
        wallet: $wallet,
        transaction: $feeTransaction,
        entryType: 'debit',
        amount: $feeAmount,
        description: 'Platform fee — 2% funding charge',
        applyToBalance: false, // fee already deducted from gross before credit
      );

      return $transaction;
    });
  }

  /**
   * Debit a wallet (used by spray engine in Phase 4).
   */
  public function debitWallet(
    Wallet $wallet,
    float $amount,
    Transaction $transaction,
    string $description = ''
  ): void {
    if ($wallet->available_balance < $amount) {
      throw new \Exception('Insufficient balance.');
    }

    $wallet->balance           -= $amount;
    $wallet->available_balance -= $amount;
    $wallet->save();

    $this->writeLedgerEntry(
      wallet: $wallet,
      transaction: $transaction,
      entryType: 'debit',
      amount: $amount,
      description: $description,
    );
  }

  /**
   * Credit a wallet (used by escrow release in Phase 4).
   */
  public function creditWallet(
    Wallet $wallet,
    float $amount,
    Transaction $transaction,
    string $description = ''
  ): void {
    $wallet->balance           += $amount;
    $wallet->available_balance += $amount;
    $wallet->save();

    $this->writeLedgerEntry(
      wallet: $wallet,
      transaction: $transaction,
      entryType: 'credit',
      amount: $amount,
      description: $description,
    );
  }

  /**
   * Write an immutable ledger entry.
   * Always records balance_before and balance_after.
   */
  private function writeLedgerEntry(
    Wallet $wallet,
    Transaction $transaction,
    string $entryType,
    float $amount,
    string $description = '',
    bool $applyToBalance = true,
  ): LedgerEntry {

    $balanceBefore = (float) $wallet->balance;

    if ($applyToBalance) {
      if ($entryType === 'credit') {
        $wallet->balance           += $amount;
        $wallet->available_balance += $amount;
      } else {
        $wallet->balance           -= $amount;
        $wallet->available_balance -= $amount;
      }
      $wallet->save();
    }

    $balanceAfter = (float) $wallet->balance;

    return LedgerEntry::create([
      'wallet_id'      => $wallet->id,
      'transaction_id' => $transaction->id,
      'entry_type'     => $entryType,
      'amount'         => $amount,
      'currency'       => $wallet->currency,
      'balance_before' => $balanceBefore,
      'balance_after'  => $balanceAfter,
      'description'    => $description,
    ]);
  }
}
