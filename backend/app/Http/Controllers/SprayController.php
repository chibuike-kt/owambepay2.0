<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Spray;
use App\Models\SpraySession;
use App\Models\Wallet;
use App\Services\SprayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SprayController extends Controller
{
    public function __construct(private SprayService $sprayService) {}

    /**
     * POST /api/events/{slug}/join
     * Guest joins event — creates session + funds guest wallet.
     * No auth required.
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
                'message'       => 'Joined successfully.',
                'session_id'    => $result['session']->id,
                'guest_token'   => $result['session']->guest_token,
                'wallet_id'     => $result['guest_wallet']->id,
                'balance'       => $result['guest_wallet']->balance,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/events/{slug}/spray
     * Guest sprays money. No auth — uses guest_token.
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
                message: $request->message ?? '',
            );

            $guestWallet->refresh();

            return response()->json([
                'message'         => 'Sprayed!',
                'spray_id'        => $spray->id,
                'amount'          => (float) $spray->amount,
                'remaining_balance' => (float) $guestWallet->available_balance,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/events/{slug}/sprays
     * Recent sprays for an event. Public.
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
