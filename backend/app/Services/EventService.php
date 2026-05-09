<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;

class EventService
{
  public function createEvent(User $host, array $data): Event
  {
    $slug    = $this->generateSlug($data['title']);
    $joinUrl = config('app.frontend_url') . '/event/' . $slug;

    // Create escrow wallet
    $escrowWallet = Wallet::create([
      'user_id'           => $host->id,
      'type'              => 'escrow',
      'currency'          => 'NGN',
      'balance'           => 0,
      'available_balance' => 0,
      'status'            => 'active',
      'metadata'          => ['type' => 'escrow', 'purpose' => 'event_escrow'],
    ]);

    // QR code via Google Charts API — no package needed
    $qrCodeUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl='
      . urlencode($joinUrl)
      . '&choe=UTF-8';

    // QR code generated on frontend — just store the join URL
    $event = Event::create([
      'host_user_id'     => $host->id,
      'escrow_wallet_id' => $escrowWallet->id,
      'title'            => $data['title'],
      'slug'             => $slug,
      'description'      => $data['description'] ?? null,
      'status'           => 'active',
      'join_url'         => $joinUrl,
      'qr_code_url'      => $joinUrl,  // store join_url here, frontend generates QR
      'starts_at'        => now(),
      'metadata'         => ['currency' => 'NGN'],
    ]);

    return $event;
  }

  public function endEvent(Event $event): Event
  {
    $event->update([
      'status'  => 'ended',
      'ends_at' => now(),
    ]);
    return $event;
  }

  private function generateSlug(string $title): string
  {
    $base = Str::slug($title);
    $slug = $base . '-' . strtolower(Str::random(6));

    while (Event::where('slug', $slug)->exists()) {
      $slug = $base . '-' . strtolower(Str::random(6));
    }

    return $slug;
  }
}
