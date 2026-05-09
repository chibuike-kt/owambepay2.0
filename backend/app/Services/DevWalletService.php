<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DevWalletService
{
  private string $baseUrl;
  private string $secretKey;

  public function __construct()
  {
    $this->baseUrl   = config('services.devwallet.base_url');
    $this->secretKey = config('services.devwallet.secret_key');
  }

  /**
   * Initialize a payment transaction.
   * Returns authorization_url to redirect the guest to.
   */
  public function initializeTransaction(
    string $email,
    int    $amountKobo,
    string $reference,
    string $callbackUrl,
    string $currency = 'NGN'
  ): array {
    $response = $this->post('/transaction/initialize', [
      'email'        => $email,
      'amount'       => $amountKobo,
      'currency'     => $currency,
      'reference'    => $reference,
      'callback_url' => $callbackUrl,
    ]);

    if (!$response['status']) {
      throw new \Exception($response['message'] ?? 'Failed to initialize payment.');
    }

    return $response['data'];
  }

  /**
   * Verify a transaction by reference.
   * Returns transaction data if successful.
   */
  public function verifyTransaction(string $reference): array
  {
    $response = $this->get("/transaction/verify/{$reference}");

    if (!$response['status']) {
      throw new \Exception($response['message'] ?? 'Transaction verification failed.');
    }

    return $response['data'];
  }

  // ── HTTP helpers ──────────────────────────────────────────────────────────

  private function post(string $endpoint, array $data): array
  {
    try {
      $response = Http::withToken($this->secretKey)
        ->acceptJson()
        ->post($this->baseUrl . $endpoint, $data);

      return $this->handleResponse($response);
    } catch (\Exception $e) {
      Log::error('DevWallet POST error', [
        'endpoint' => $endpoint,
        'error'    => $e->getMessage(),
      ]);
      throw new \Exception('Payment provider unreachable. Please try again.');
    }
  }

  private function get(string $endpoint): array
  {
    try {
      $response = Http::withToken($this->secretKey)
        ->acceptJson()
        ->get($this->baseUrl . $endpoint);

      return $this->handleResponse($response);
    } catch (\Exception $e) {
      Log::error('DevWallet GET error', [
        'endpoint' => $endpoint,
        'error'    => $e->getMessage(),
      ]);
      throw new \Exception('Payment provider unreachable. Please try again.');
    }
  }

  private function handleResponse(Response $response): array
  {
    $body = $response->json();

    Log::info('DevWallet response', [
      'status'  => $response->status(),
      'body'    => $body,
    ]);

    if ($response->failed()) {
      Log::error('DevWallet error response', [
        'status' => $response->status(),
        'body'   => $body,
      ]);
      throw new \Exception($body['message'] ?? 'Payment provider error.');
    }

    return $body ?? [];
  }
}
