<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\DevWalletService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletController extends Controller
{
    public function __construct(
        private WalletService    $walletService,
        private DevWalletService $devWalletService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $wallets = Wallet::where('user_id', $request->user()->id)
            ->where('type', 'personal')
            ->orderBy('currency')
            ->get();

        return response()->json(['wallets' => $wallets]);
    }

    /**
     * POST /api/wallets/initialize
     * Initializes a DevWallet payment. Returns authorization_url.
     * Host wallet funding only.
     */
    public function initialize(Request $request): JsonResponse
    {
        $request->validate([
            'amount'   => ['required', 'numeric', 'min:100'],
            'currency' => ['required', 'in:NGN,USD'],
        ]);

        $wallet = Wallet::where('user_id', $request->user()->id)
            ->where('currency', $request->currency)
            ->where('type', 'personal')
            ->first();

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found.'], 404);
        }

        $grossAmount = (float) $request->amount;
        $amountKobo  = (int) ($grossAmount * 100); // DevWallet expects kobo
        $reference   = Transaction::generateReference('FND');
        $callbackUrl = config('app.frontend_url')
            . '/dashboard/fund/verify?reference=' . $reference
            . '&wallet_id=' . $wallet->id;

        try {
            $data = $this->devWalletService->initializeTransaction(
                email: $request->user()->email,
                amountKobo: $amountKobo,
                reference: $reference,
                callbackUrl: $callbackUrl,
                currency: $request->currency,
            );

            // Store pending transaction so we can verify later
            Transaction::create([
                'reference'       => $reference,
                'type'            => 'wallet_funding',
                'status'          => 'pending',
                'amount'          => $grossAmount,
                'currency'        => $request->currency,
                'wallet_id'       => $wallet->id,
                'provider'        => 'devwallet',
                'narration'       => 'Wallet funding via DevWallet',
                'idempotency_key' => Str::uuid(),
                'metadata'        => [
                    'gross_amount'      => $grossAmount,
                    'amount_kobo'       => $amountKobo,
                    'authorization_url' => $data['authorization_url'] ?? null,
                ],
            ]);

            return response()->json([
                'reference'         => $reference,
                'authorization_url' => $data['authorization_url'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/wallets/verify
     * Called after DevWallet redirects back. Verifies and credits wallet.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'reference' => ['required', 'string'],
        ]);

        $reference = $request->reference;

        // Find the pending transaction
        $pendingTx = Transaction::where('reference', $reference)
            ->where('status', 'pending')
            ->first();

        if (!$pendingTx) {
            // Check if already verified
            $existing = Transaction::where('reference', $reference)
                ->where('status', 'success')
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Payment already verified.',
                    'wallet'  => Wallet::find($existing->wallet_id),
                ]);
            }

            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        $wallet = Wallet::find($pendingTx->wallet_id);
        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found.'], 404);
        }

        try {
            // Verify with DevWallet
            $data   = $this->devWalletService->verifyTransaction($reference);
            $status = $data['status'] ?? '';

            if ($status !== 'success') {
                $pendingTx->update([
                    'status'         => 'failed',
                    'failure_reason' => "DevWallet status: {$status}",
                ]);
                return response()->json(['message' => 'Payment was not successful.'], 422);
            }

            // Amount DevWallet returns is in kobo — convert to naira
            $grossAmount = ($data['amount'] ?? 0) / 100;

            // Mark pending tx as success and credit wallet
            $pendingTx->update(['status' => 'success']);

            $transaction = $this->walletService->creditFromVerifiedPayment(
                wallet: $wallet,
                grossAmount: $grossAmount,
                reference: 'VERIFIED-' . $reference,
                provider: 'devwallet',
            );

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
     * POST /api/wallets/fund
     * Direct fund — used for guest wallets (internal/sandbox only).
     */
    public function fund(Request $request): JsonResponse
    {
        $request->validate([
            'amount'   => ['required', 'numeric', 'min:100'],
            'currency' => ['required', 'in:NGN,USD'],
        ]);

        $wallet = Wallet::where('user_id', $request->user()->id)
            ->where('currency', $request->currency)
            ->where('type', 'personal')
            ->first();

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found.'], 404);
        }

        try {
            $transaction = $this->walletService->fundWallet(
                wallet: $wallet,
                grossAmount: (float) $request->amount,
                provider: 'devwallet',
            );

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

    public function ledger(Request $request): JsonResponse
    {
        $request->validate(['currency' => ['required', 'in:NGN,USD']]);

        $wallet = Wallet::where('user_id', $request->user()->id)
            ->where('currency', $request->currency)
            ->where('type', 'personal')
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
