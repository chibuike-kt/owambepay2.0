<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Spray;
use App\Models\SpraySession;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\DevWalletService;
use App\Services\SprayService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SprayController extends Controller
{
    public function __construct(
        private SprayService     $sprayService,
        private WalletService    $walletService,
        private DevWalletService $devWalletService,
    ) {}

    /**
     * POST /api/events/{slug}/initialize-guest
     * Guest submits name + amount → we initialize DevWallet payment.
     * No auth required.
     */
    public function initializeGuest(Request $request, string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)->first();
        if (!$event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        if (!$event->isActive()) {
            return response()->json(['message' => 'This event has ended.'], 422);
        }

        $request->validate([
            'guest_name'  => ['required', 'string', 'max:60'],
            'fund_amount' => ['required', 'numeric', 'min:100'],
        ]);

        $guestName   = trim($request->guest_name);
        $fundAmount  = (float) $request->fund_amount;
        $amountKobo  = (int) ($fundAmount * 100);
        $reference   = Transaction::generateReference('GST');

        // Use a guest placeholder email for DevWallet
        $guestEmail  = Str::slug($guestName) . '+guest@owambepay.local';

        $callbackUrl = config('app.frontend_url')
            . '/event/' . $slug
            . '/verify?reference=' . $reference
            . '&guest_name=' . urlencode($guestName)
            . '&fund_amount=' . $fundAmount;

        try {
            $data = $this->devWalletService->initializeTransaction(
                email: $guestEmail,
                amountKobo: $amountKobo,
                reference: $reference,
                callbackUrl: $callbackUrl,
            );

            // Store intent so we can verify later
            // We use event metadata to track guest intent before wallet exists
            cache()->put("guest_intent_{$reference}", [
                'event_id'    => $event->id,
                'event_slug'  => $slug,
                'guest_name'  => $guestName,
                'fund_amount' => $fundAmount,
                'reference'   => $reference,
            ], now()->addHours(2));

            return response()->json([
                'reference'         => $reference,
                'authorization_url' => $data['authorization_url'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/events/{slug}/verify-guest
     * Called after DevWallet redirects back.
     * Verifies payment, creates guest wallet + session.
     * No auth required.
     */
    public function verifyGuest(Request $request, string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)->first();
        if (!$event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $request->validate([
            'reference'   => ['required', 'string'],
            'guest_name'  => ['required', 'string'],
            'fund_amount' => ['required', 'numeric'],
        ]);

        $reference  = $request->reference;
        $guestName  = $request->guest_name;
        $fundAmount = (float) $request->fund_amount;

        // Check cache for intent
        $intent = cache()->get("guest_intent_{$reference}");

        try {
            // Verify with DevWallet
            $data   = $this->devWalletService->verifyTransaction($reference);
            $status = $data['status'] ?? '';

            if ($status !== 'success') {
                return response()->json([
                    'message' => 'Payment was not successful. Status: ' . $status,
                ], 422);
            }

            // Amount from DevWallet is in kobo
            $verifiedAmount = ($data['amount'] ?? 0) / 100;

            // Use verified amount if available, fallback to requested
            $grossAmount = $verifiedAmount > 0 ? $verifiedAmount : $fundAmount;

            // Create guest wallet + session + credit
            $result = $this->sprayService->joinEventWithPayment(
                event: $event,
                guestName: $guestName,
                grossAmount: $grossAmount,
                reference: $reference,
            );

            // Clear intent from cache
            if ($intent) cache()->forget("guest_intent_{$reference}");

            return response()->json([
                'message'       => 'Payment verified. Welcome to the celebration!',
                'session_id'    => $result['session']->id,
                'guest_token'   => $result['session']->guest_token,
                'wallet_id'     => $result['guest_wallet']->id,
                'balance'       => (float) $result['guest_wallet']->balance,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/events/{slug}/join
     * Kept for internal/sandbox use only.
     */
    public function join(Request $request, string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)->first();
        if (!$event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $request->validate([
            'guest_name'  => ['required', 'string', 'max:60'],
            'fund_amount' => ['required', 'numeric', 'min:100'],
        ]);

        try {
            $result = $this->sprayService->joinEvent(
                event: $event,
                guestName: $request->guest_name,
                fundAmount: (float) $request->fund_amount,
            );

            return response()->json([
                'message'     => 'Joined successfully.',
                'session_id'  => $result['session']->id,
                'guest_token' => $result['session']->guest_token,
                'wallet_id'   => $result['guest_wallet']->id,
                'balance'     => (float) $result['guest_wallet']->balance,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/events/{slug}/spray
     */
    public function spray(Request $request, string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)->first();
        if (!$event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $request->validate([
            'guest_token' => ['required', 'string'],
            'amount'      => ['required', 'numeric', 'min:1'],
            'note_type'   => ['nullable', 'in:100,200,500,1000'],
            'message'     => ['nullable', 'string', 'max:100'],
        ]);

        $session = SpraySession::where('guest_token', $request->guest_token)
            ->where('event_id', $event->id)
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Invalid session.'], 403);
        }

        $guestWalletId = $session->metadata['guest_wallet_id'] ?? null;
        $guestWallet   = $guestWalletId ? Wallet::find($guestWalletId) : null;

        if (!$guestWallet) {
            return response()->json(['message' => 'Guest wallet not found.'], 404);
        }

        try {
            $spray = $this->sprayService->spray(
                event: $event,
                session: $session,
                guestWallet: $guestWallet,
                amount: (float) $request->amount,
                noteType: $request->note_type ?? '100',
                message: $request->message  ?? '',
            );

            $guestWallet->refresh();

            return response()->json([
                'message'           => 'Sprayed!',
                'spray_id'          => $spray->id,
                'amount'            => (float) $spray->amount,
                'remaining_balance' => (float) $guestWallet->available_balance,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/events/{slug}/sprays
     */
    public function index(string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)->first();
        if (!$event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        $sprays = Spray::where('event_id', $event->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'guest_name', 'amount', 'currency', 'note_type', 'message', 'created_at']);

        return response()->json(['sprays' => $sprays]);
    }
}
