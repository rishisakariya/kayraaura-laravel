<?php

namespace App\Services\Shiprocket;

use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShiprocketClient
{
    public function __construct()
    {
    }

    public function login(): array
    {
        Log::channel('thirdparty')->info('Shiprocket API: login requested', [
            'mock' => $this->mockEnabled(),
        ]);

        if ($this->mockEnabled()) {
            return [
                'mock' => true,
                'token' => 'MOCK-SHIPROCKET-TOKEN',
            ];
        }

        $email = config('shiprocket.credentials.email');
        $password = config('shiprocket.credentials.password');

        if (!$email || !$password) {
            throw new DomainException('Shiprocket credentials are not configured');
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post($this->url('auth/login'), [
                'email' => $email,
                'password' => $password,
            ]);

        return $this->decodeResponse($response, 'Shiprocket auth failed');
    }

    public function token(): string
    {
        return Cache::remember(
            $this->tokenCacheKey(),
            now()->addMinutes((int) config('shiprocket.token_cache_minutes', 1439)),
            fn () => (string) $this->login()['token']
        );
    }

    public function createAdhocOrder(array $payload): array
    {
        Log::channel('thirdparty')->info('Shiprocket API: create adhoc order requested', [
            'order_reference' => $payload['order_id'] ?? null,
            'mock' => $this->mockEnabled(),
        ]);

        if ($this->mockEnabled()) {
            return [
                'mock' => true,
                'shipment_id' => 111111,
                'order_id' => 'MOCK-SR-ORDER',
            ];
        }

        $response = Http::timeout(30)
            ->withToken($this->token())
            ->acceptJson()
            ->post($this->url('orders/create/adhoc'), $payload);

        return $this->decodeResponse($response, 'Shiprocket order creation failed');
    }

    public function createReturnOrder(array $payload): array
    {
        Log::channel('thirdparty')->info('Shiprocket API: create return order requested', [
            'order_reference' => $payload['order_id'] ?? null,
            'mock' => $this->mockEnabled(),
        ]);

        if ($this->mockEnabled()) {
            return [
                'mock' => true,
                'shipment_id' => 222222,
                'order_id' => 'MOCK-SR-RETURN-ORDER',
            ];
        }

        $response = Http::timeout(30)
            ->withToken($this->token())
            ->acceptJson()
            ->post($this->url('orders/create/return'), $payload);

        return $this->decodeResponse($response, 'Shiprocket return order creation failed');
    }

    public function assignAwb(int $shipmentId, ?int $courierId = null, bool $isReturn = false): array
    {
        Log::channel('thirdparty')->info('Shiprocket API: assign AWB requested', [
            'shipment_id' => $shipmentId,
            'courier_id' => $courierId,
            'is_return' => $isReturn,
            'mock' => $this->mockEnabled(),
        ]);

        if ($this->mockEnabled()) {
            $awb = ($isReturn ? 'RMOCK' : 'MOCK') . now()->format('YmdHis');

            return [
                'mock' => true,
                'awb_code' => $awb,
                'courier_name' => 'Mock Courier',
            ];
        }

        $body = [
            'shipment_id' => $shipmentId,
            'is_return' => $isReturn ? 1 : 0,
        ];

        if ($courierId) {
            $body['courier_id'] = $courierId;
        }

        $response = Http::timeout(30)
            ->withToken($this->token())
            ->acceptJson()
            ->post($this->url('courier/assign/awb'), $body);

        return $this->decodeResponse($response, 'Shiprocket AWB assignment failed');
    }

    public function generatePickup(int $shipmentId): array
    {
        Log::channel('thirdparty')->info('Shiprocket API: generate pickup requested', [
            'shipment_id' => $shipmentId,
            'mock' => $this->mockEnabled(),
        ]);

        if ($this->mockEnabled()) {
            return [
                'mock' => true,
                'pickup_status' => true,
            ];
        }

        $body = [
            'shipment_id' => [$shipmentId],
        ];

        $response = Http::timeout(30)
            ->withToken($this->token())
            ->acceptJson()
            ->post($this->url('courier/generate/pickup'), $body);

        return $this->decodeResponse($response, 'Shiprocket pickup generation failed');
    }

    public function generateManifest(array $shipmentIds): array
    {
        Log::channel('thirdparty')->info('Shiprocket API: generate manifest requested', [
            'shipment_ids' => array_values($shipmentIds),
            'mock' => $this->mockEnabled(),
        ]);

        if ($this->mockEnabled()) {
            return [
                'mock' => true,
                'status' => true,
            ];
        }

        $response = Http::timeout(30)
            ->withToken($this->token())
            ->acceptJson()
            ->post($this->url('manifests/generate'), [
                'shipment_id' => array_values($shipmentIds),
            ]);

        return $this->decodeResponse($response, 'Shiprocket manifest generation failed');
    }

    public function generateLabel(array $shipmentIds): array
    {
        Log::channel('thirdparty')->info('Shiprocket API: generate label requested', [
            'shipment_ids' => array_values($shipmentIds),
            'mock' => $this->mockEnabled(),
        ]);

        if ($this->mockEnabled()) {
            return [
                'mock' => true,
                'label_pdf_url' => null,
            ];
        }

        $response = Http::timeout(30)
            ->withToken($this->token())
            ->acceptJson()
            ->post($this->url('courier/generate/label'), [
                'shipment_id' => array_values($shipmentIds),
            ]);

        return $this->decodeResponse($response, 'Shiprocket label generation failed');
    }

    public function trackAwb(string $awbCode): array
    {
        Log::channel('thirdparty')->info('Shiprocket API: track AWB requested', [
            'awb' => $awbCode,
            'mock' => $this->mockEnabled(),
        ]);

        if ($this->mockEnabled()) {
            return [
                'mock' => true,
                'awb' => $awbCode,
                'current_status' => 'IN TRANSIT',
                'shipment_status' => 'IN TRANSIT',
                'scans' => [
                    [
                        'date' => now()->format('Y-m-d H:i:s'),
                        'status' => 'IN TRANSIT',
                        'activity' => 'Mock shipment scan',
                        'location' => 'Mock Location',
                        'sr-status' => '18',
                        'sr-status-label' => 'IN TRANSIT',
                    ],
                ],
                'track_url' => "https://shiprocket.co/tracking/{$awbCode}",
            ];
        }

        $response = Http::timeout(30)
            ->withToken($this->token())
            ->acceptJson()
            ->get($this->url('courier/track/awb/' . rawurlencode($awbCode)));

        return $this->decodeResponse($response, 'Shiprocket tracking failed');
    }

    public function cancelOrder(string $srOrderId): array
    {
        Log::channel('thirdparty')->info('Shiprocket API: cancel order requested', [
            'sr_order_id' => $srOrderId,
            'mock' => $this->mockEnabled(),
        ]);

        if ($this->mockEnabled()) {
            return [
                'mock' => true,
                'status' => true,
                'message' => 'Cancelled',
            ];
        }

        $response = Http::timeout(30)
            ->withToken($this->token())
            ->acceptJson()
            ->post($this->url('orders/cancel'), ['ids' => [(int) $srOrderId]]);

        return $this->decodeResponse($response, 'Shiprocket order cancel failed');
    }

    private function mockEnabled(): bool
    {
        return (bool) config('shiprocket.mock');
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('shiprocket.base_url', 'https://apiv2.shiprocket.in'), '/');

        if ($url === '') {
            throw new DomainException('Shiprocket base URL is not configured');
        }

        return $url;
    }

    private function url(string $path): string
    {
        return $this->baseUrl() . '/v1/external/' . ltrim($path, '/');
    }

    private function tokenCacheKey(): string
    {
        $environment = config('shiprocket.env', 'staging');
        $email = (string) config('shiprocket.credentials.email', 'unknown');

        return "shiprocket:token:{$environment}:" . sha1($email);
    }

    private function decodeResponse(Response $response, string $defaultMessage): array
    {
        $payload = $response->json();

        if (!is_array($payload)) {
            $payload = ['raw' => $response->body()];
        }

        if (!$response->successful()) {
            $message = Arr::get($payload, 'message')
                ?? Arr::get($payload, 'error')
                ?? Arr::get($payload, 'error.message')
                ?? Arr::get($payload, 'errors.0.message')
                ?? $defaultMessage;

            Log::channel('thirdparty')->error('Shiprocket API: request failed', [
                'operation' => $defaultMessage,
                'http_status' => $response->status(),
                'message' => (string) $message,
            ]);

            throw new DomainException((string) $message);
        }

        Log::channel('thirdparty')->info('Shiprocket API: request succeeded', [
            'operation' => $defaultMessage,
            'http_status' => $response->status(),
        ]);

        return $payload;
    }
}

