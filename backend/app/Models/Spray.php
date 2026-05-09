<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Spray extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'spray_session_id',
        'guest_wallet_id',
        'escrow_wallet_id',
        'transaction_id',
        'guest_name',
        'amount',
        'currency',
        'note_type',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function spraySession(): BelongsTo
    {
        return $this->belongsTo(SpraySession::class);
    }
}
