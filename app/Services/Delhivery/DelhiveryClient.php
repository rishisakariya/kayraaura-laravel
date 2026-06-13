<?php

namespace App\Services\Delhivery;

use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DelhiveryClient
{
    public function createShipment(array $payload): array
    {
        if ($this->mockEnabled()) {
            return $this->mockCreateShipmentResponse($payload);
        }

        $response = Http::asForm()
            ->withHeaders($this->headers())
            ->timeout(30)
            ->post($this->url('create'), [
                'format' => 'json',
                'data' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

        return $this->decodeResponse($response, 'Delhivery shipment creation failed');
    }

    public function trackShipment(string $waybill): array
    {
        if ($this->mockEnabled()) {
            return $this->mockTrackShipmentResponse($waybill);
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get($this->url('track'), [
                'waybill' => $waybill,
                'token' => $this->token(),
            ]);

        return $this->decodeResponse($response, 'Delhivery tracking failed');
    }

    public function cancelShipment(string $waybill): array
    {
        if ($this->mockEnabled()) {
            return $this->mockCancelShipmentResponse($waybill);
        }

        $response = Http::asForm()
            ->withHeaders($this->headers())
            ->timeout(30)
            ->post($this->url('cancel'), [
                'waybill' => $waybill,
                'cancellation' => 'true',
            ]);

        return $this->decodeResponse($response, 'Delhivery shipment cancellation failed');
    }

    private function mockEnabled(): bool
    {
        return (bool) config('delhivery.mock');
    }

    private function mockCreateShipmentResponse(array $payload): array
    {
        $orderNumber = $payload['shipments'][0]['order'] ?? 'TEST';
        $isReversePickup = ($payload['shipments'][0]['payment_mode'] ?? null) === 'Pickup';
        $waybill = ($isReversePickup ? 'RMOCK' : 'MOCK') . now()->format('YmdHis');

        return [
            'mock' => true,
            'success' => true,
            'packages' => [
                [
                    'waybill' => $waybill,
                    'wbn' => $waybill,
                    'refnum' => $orderNumber,
                    'order' => $orderNumber,
                    'status' => $isReversePickup ? 'Pickup Scheduled' : 'Manifested',
                ],
            ],
        ];
    }

    private function mockTrackShipmentResponse(string $waybill): array
    {
        return [
            'mock' => true,
            'ShipmentData' => [
                [
                    'Shipment' => [
                        'AWB' => $waybill,
                        'Status' => [
                            'Status' => 'Manifested',
                            'StatusLocation' => 'Local Mock',
                            'Instructions' => 'Mock shipment created for local testing',
                        ],
                        'Scans' => [
                            [
                                'ScanDetail' => [
                                    'Scan' => 'Manifested',
                                    'ScannedLocation' => 'Local Mock',
                                    'Instructions' => 'Mock shipment created for local testing',
                                    'ScanDateTime' => now()->format('Y-m-d H:i:s'),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function mockCancelShipmentResponse(string $waybill): array
    {
        return [
            'mock' => true,
            'success' => true,
            'waybill' => $waybill,
            'status' => 'Cancelled',
        ];
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Token ' . $this->token(),
            'Accept' => 'application/json',
        ];
    }

    private function token(): string
    {
        $token = config('delhivery.token');

        if (!$token) {
            throw new DomainException('Delhivery token is not configured');
        }

        return $token;
    }

    private function url(string $key): string
    {
        $environment = config('delhivery.env') === 'production' ? 'production' : 'staging';
        $url = config("delhivery.urls.{$environment}.{$key}");

        if (!$url) {
            throw new DomainException("Delhivery {$key} URL is not configured");
        }

        return $url;
    }

    private function decodeResponse(Response $response, string $defaultMessage): array
    {
        $payload = $response->json();

        if ($payload === null) {
            $payload = ['raw' => $response->body()];
        }

        if (!$response->successful()) {
            $message = $payload['error']['message']
                ?? $payload['error']['description']
                ?? $payload['rmk']
                ?? $defaultMessage;

            throw new DomainException($message);
        }

        return $payload;
    }
}
