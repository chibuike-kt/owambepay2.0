<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    /**
     * GET /api/wallets
     * Returns all wallets for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $wallets = Wallet::where('user_id', $request->user()->id)
            ->orderBy('currency')
            ->get();

        return response()->json(['wallets' => $wallets]);
    }

    /**
     * POST /api/wallets/fund
     * Funds a wallet. Calls WalletService which handles fee + ledger.
     */
    public function fund(Request $request): JsonResponse
    {
        $request->validate([
            'amount'   => ['required', 'numeric', 'min:100'],
            'currency' => ['required', 'in:NGN,USD'],
        ]);

        $wallet = Wallet::where('user_id', $request->user()->id)
            ->where('currency', $request->currency)
            ->first();

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found.'], 404);
        }

        if (!$wallet->isActive()) {
            return response()->json(['message' => 'Wallet is not active.'], 422);
        }

        try {
            $idempotencyKey = $request->header('Idempotency-Key') ?? Str::uuid();

            // DevWallet stub — in Phase 5 this calls the real DevWallet API
            $providerReference = 'DEVWALLET-' . strtoupper(Str::random(10));

            $transaction = $this->walletService->fundWallet(
                wallet: $wallet,
                grossAmount: (float) $request->amount,
                provider: 'devwallet',
                providerReference: $providerReference,
                idempotencyKey: $idempotencyKey,
            );

            // Reload wallet to get updated balance
            $wallet->refresh();

            return response()->json([
                'message'     => 'Wallet funded successfully.',
                'transaction' => $transaction,
                'wallet'      => $wallet,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/wallets/ledger
     * Returns ledger entries for a wallet.
     */
    public function ledger(Request $request): JsonResponse
    {
        $request->validate([
            'currency' => ['required', 'in:NGN,USD'],
        ]);

        $wallet = Wallet::where('user_id', $request->user()->id)
            ->where('currency', $request->currency)
            ->first();

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found.'], 404);
        }

        $entries = $wallet->ledgerEntries()
            ->with('transaction')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['ledger' => $entries]);
    }
}
