<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasUuids;

    protected $fillable = [
        'host_user_id',
        'escrow_wallet_id',
        'title',
        'slug',
        'description',
        'status',
        'qr_code_url',
        'join_url',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
            'metadata'  => 'array',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function escrowWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'escrow_wallet_id');
    }

    public function spraySessions(): HasMany
    {
        return $this->hasMany(SpraySession::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function totalReceived(): float
    {
        return (float) ($this->escrowWallet?->balance ?? 0);
    }
}
