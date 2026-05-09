<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'reference',
        'type',
        'status',
        'amount',
        'currency',
        'wallet_id',
        'provider',
        'narration',
        'metadata',
        'failure_reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount'   => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public static function generateReference(string $prefix = 'TXN'): string
    {
        return strtoupper($prefix) . '-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -8));
    }
}
