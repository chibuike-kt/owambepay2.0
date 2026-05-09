<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class EventService
{
  public function createEvent(User $host, array $data): Event
  {
    $slug    = $this->generateSlug($data['title']);
    $joinUrl = config('app.frontend_url') . '/event/' . $slug;

    // Create escrow wallet — owned by the host but isolated per event
    $escrowWallet = Wallet::create([
      'user_id'           => $host->id,
      'currency'          => 'NGN',
      'balance'           => 0,
      'available_balance' => 0,
      'status'            => 'active',
      'metadata'          => ['type' => 'escrow', 'purpose' => 'event_escrow'],
    ]);

    // Generate QR code as SVG stored as base64 data URI
    $qrSvg    = QrCode::format('svg')->size(300)->generate($joinUrl);
    $qrBase64 = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

    $event = Event::create([
      'host_user_id'     => $host->id,
      'escrow_wallet_id' => $escrowWallet->id,
      'title'            => $data['title'],
      'slug'             => $slug,
      'description'      => $data['description'] ?? null,
      'status'           => 'active',
      'join_url'         => $joinUrl,
      'qr_code_url'      => $qrBase64,
      'starts_at'        => now(),
      'metadata'         => ['currency' => 'NGN'],
    ]);

    return $event;
  }

  public function endEvent(Event $event): Event
  {
    $event->update([
      'status'   => 'ended',
      'ends_at'  => now(),
    ]);
    return $event;
  }

  private function generateSlug(string $title): string
  {
    $base = Str::slug($title);
    $slug = $base . '-' . strtolower(Str::random(6));

    // Ensure uniqueness
    while (Event::where('slug', $slug)->exists()) {
      $slug = $base . '-' . strtolower(Str::random(6));
    }

    return $slug;
  }
}
