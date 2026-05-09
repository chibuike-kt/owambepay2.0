<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpraySession extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'guest_name',
        'guest_token',
        'total_sprayed',
        'currency',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'total_sprayed' => 'decimal:4',
            'metadata'      => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
