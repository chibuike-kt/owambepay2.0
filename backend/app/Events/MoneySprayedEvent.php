<?php

namespace App\Events;

use App\Models\Spray;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MoneySprayedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Spray $spray) {}

    public function broadcastOn(): Channel
    {
        // Channel name includes event slug so only guests in this
        // event receive the broadcast
        return new Channel('event.' . $this->spray->event->slug);
    }

    public function broadcastAs(): string
    {
        return 'money.sprayed';
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->spray->id,
            'guest_name' => $this->spray->guest_name,
            'amount'     => (float) $this->spray->amount,
            'currency'   => $this->spray->currency,
            'note_type'  => $this->spray->note_type,
            'message'    => $this->spray->message,
            'created_at' => $this->spray->created_at,
        ];
    }
}
