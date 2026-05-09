<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private EventService $eventService) {}

    /**
     * GET /api/events
     * Host's event list.
     */
    public function index(Request $request): JsonResponse
    {
        $events = Event::where('host_user_id', $request->user()->id)
            ->with('escrowWallet')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($event) => [
                'id'             => $event->id,
                'title'          => $event->title,
                'slug'           => $event->slug,
                'status'         => $event->status,
                'join_url'       => $event->join_url,
                'qr_code_url'    => $event->qr_code_url,
                'total_received' => $event->totalReceived(),
                'spray_count'    => $event->spraySessions()->count(),
                'starts_at'      => $event->starts_at,
                'ends_at'        => $event->ends_at,
                'created_at'     => $event->created_at,
            ]);

        return response()->json(['events' => $events]);
    }

    /**
     * POST /api/events
     * Create a new event.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title'       => ['required', 'string', 'min:3', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $event = $this->eventService->createEvent(
            $request->user(),
            $request->only('title', 'description')
        );

        return response()->json([
            'message' => 'Event created successfully.',
            'event'   => $event,
        ], 201);
    }

    /**
     * GET /api/events/{slug}
     * Public — no auth. Used by guests to load the event.
     */
    public function show(string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)
            ->with('host:id,name')
            ->first();

        if (!$event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        return response()->json([
            'event' => [
                'id'          => $event->id,
                'title'       => $event->title,
                'slug'        => $event->slug,
                'status'      => $event->status,
                'host_name'   => $event->host->name,
                'join_url'    => $event->join_url,
                'qr_code_url' => $event->qr_code_url,
                'starts_at'   => $event->starts_at,
            ],
        ]);
    }

    /**
     * POST /api/events/{slug}/end
     * Host ends the event.
     */
    public function end(Request $request, string $slug): JsonResponse
    {
        $event = Event::where('slug', $slug)
            ->where('host_user_id', $request->user()->id)
            ->first();

        if (!$event) {
            return response()->json(['message' => 'Event not found.'], 404);
        }

        if (!$event->isActive()) {
            return response()->json(['message' => 'Event is not active.'], 422);
        }

        $event = $this->eventService->endEvent($event);

        return response()->json([
            'message' => 'Event ended.',
            'event'   => $event,
        ]);
    }
}
