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
  const FUNDING_FEE_RATE    = 0.02;
  const WITHDRAWAL_FEE_RATE = 0.02;

  public function createWallet(User $user, string $currency = 'NGN'): Wallet
  {
    return Wallet::firstOrCreate(
      ['user_id' => $user->id, 'currency' => $currency, 'type' => 'personal'],
      ['balance' => 0, 'available_balance' => 0, 'status' => 'active']
    );
  }

  /**
   * Credit wallet after a verified DevWallet payment.
   * Gross amount is in Naira (not kobo).
   */
  public function creditFromVerifiedPayment(
    Wallet $wallet,
    float  $grossAmount,
    string $reference,
    string $provider = 'devwallet'
  ): Transaction {
    if (!$wallet->isActive()) {
      throw new \Exception('Wallet is not active.');
    }

    // Idempotency — don't double-credit same reference
    $existing = Transaction::where('reference', $reference)->first();
    if ($existing) {
      return $existing;
    }

    $feeAmount = round($grossAmount * self::FUNDING_FEE_RATE, 4);
    $netAmount = round($grossAmount - $feeAmount, 4);

    return DB::transaction(function () use (
      $wallet,
      $grossAmount,
      $netAmount,
      $feeAmount,
      $reference,
      $provider
    ) {
      $wallet = Wallet::lockForUpdate()->find($wallet->id);

      // Main funding transaction
      $transaction = Transaction::create([
        'reference'       => $reference,
        'type'            => 'wallet_funding',
        'status'          => 'success',
        'amount'          => $netAmount,
        'currency'        => $wallet->currency,
        'wallet_id'       => $wallet->id,
        'provider'        => $provider,
        'narration'       => "Wallet funded via {$provider}",
        'idempotency_key' => Str::uuid(),
        'metadata'        => [
          'gross_amount' => $grossAmount,
          'fee_amount'   => $feeAmount,
          'reference'    => $reference,
        ],
      ]);

      // Credit ledger entry
      $this->writeLedgerEntry(
        wallet: $wallet,
        transaction: $transaction,
        entryType: 'credit',
        amount: $netAmount,
        description: "Funding credit — gross ₦{$grossAmount}, fee ₦{$feeAmount}",
      );

      // Fee ledger entry
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
        applyToBalance: false,
      );

      return $transaction;
    });
  }

  /**
   * Direct fund (used internally for guest wallets / sandbox).
   */
  public function fundWallet(
    Wallet $wallet,
    float  $grossAmount,
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

    if ($idempotencyKey) {
      $existing = Transaction::where('idempotency_key', $idempotencyKey)->first();
      if ($existing) return $existing;
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
      $wallet = Wallet::lockForUpdate()->find($wallet->id);

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
          'gross_amount'       => $grossAmount,
          'fee_amount'         => $feeAmount,
          'provider_reference' => $providerReference,
        ],
      ]);

      $this->writeLedgerEntry(
        wallet: $wallet,
        transaction: $transaction,
        entryType: 'credit',
        amount: $netAmount,
        description: "Funding credit — gross ₦{$grossAmount}, fee ₦{$feeAmount}",
      );

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
        applyToBalance: false,
      );

      return $transaction;
    });
  }

  public function debitWallet(
    Wallet      $wallet,
    float       $amount,
    Transaction $transaction,
    string      $description = ''
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

  public function creditWallet(
    Wallet      $wallet,
    float       $amount,
    Transaction $transaction,
    string      $description = ''
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

  private function writeLedgerEntry(
    Wallet      $wallet,
    Transaction $transaction,
    string      $entryType,
    float       $amount,
    string      $description = '',
    bool        $applyToBalance = true,
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

    return LedgerEntry::create([
      'wallet_id'      => $wallet->id,
      'transaction_id' => $transaction->id,
      'entry_type'     => $entryType,
      'amount'         => $amount,
      'currency'       => $wallet->currency,
      'balance_before' => $balanceBefore,
      'balance_after'  => (float) $wallet->balance,
      'description'    => $description,
    ]);
  }
}
