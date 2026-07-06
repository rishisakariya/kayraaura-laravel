<?php

namespace App\Services\Delhivery;

use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DelhiveryClient
{
    public function createShipment(array $payload): array
    {
        $orderReference = $payload['shipments'][0]['order'] ?? null;

        Log::channel('thirdparty')->info('Delhivery API: create shipment requested', [
            'order_reference' => $orderReference,
            'payment_mode' => $payload['shipments'][0]['payment_mode'] ?? null,
        ]);

        if ($this->mockEnabled()) {
            $response = $this->mockCreateShipmentResponse($payload);

            Log::channel('thirdparty')->info('Delhivery API: create shipment mock response', [
                'order_reference' => $orderReference,
                'waybill' => $response['packages'][0]['waybill'] ?? null,
            ]);

            return $response;
        }

        $response = Http::asForm()
            ->withHeaders($this->headers())
            ->timeout(30)
            ->post($this->url('create'), [
                'format' => 'json',
                'data' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

        $decoded = $this->decodeResponse($response, 'Delhivery shipment creation failed');

        Log::channel('thirdparty')->info('Delhivery API: create shipment response', [
            'order_reference' => $orderReference,
            'http_status' => $response->status(),
            'waybill' => $decoded['packages'][0]['waybill'] ?? $decoded['packages'][0]['wbn'] ?? null,
        ]);

        return $decoded;
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

    public function expectedTat(string $originPin, string $destinationPin, string $mot = 'S'): array
    {
        if ($this->mockEnabled()) {
            return $this->mockExpectedTatResponse($originPin, $destinationPin);
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get($this->url('expected_tat'), [
                'origin_pin' => $originPin,
                'destination_pin' => $destinationPin,
                'mot' => $mot,
                'pdt' => 'B2C',
            ]);

        return $this->decodeResponse($response, 'Delhivery expected TAT lookup failed');
    }

    public function trackByOrderReference(string $orderReference): array
    {
        if ($this->mockEnabled()) {
            return $this->mockTrackByOrderReferenceResponse($orderReference);
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get($this->url('track'), [
                'ref_ids' => $orderReference,
                'token' => $this->token(),
            ]);

        $payload = $response->json();

        if (!is_array($payload)) {
            return [
                'success' => false,
                'raw' => $response->body(),
                'http_status' => $response->status(),
            ];
        }

        $payload['http_status'] = $response->status();

        return $payload;
    }

    public function cancelShipment(string $waybill): array
    {
        Log::channel('thirdparty')->info('Delhivery API: cancel shipment requested', [
            'waybill' => $waybill,
        ]);

        if ($this->mockEnabled()) {
            $response = $this->mockCancelShipmentResponse($waybill);

            Log::channel('thirdparty')->info('Delhivery API: cancel shipment mock response', [
                'waybill' => $waybill,
            ]);

            return $response;
        }

        $response = Http::asForm()
            ->withHeaders($this->headers())
            ->timeout(30)
            ->post($this->url('cancel'), [
                'waybill' => $waybill,
                'cancellation' => 'true',
            ]);

        $decoded = $this->decodeResponse($response, 'Delhivery shipment cancellation failed');

        Log::channel('thirdparty')->info('Delhivery API: cancel shipment response', [
            'waybill' => $waybill,
            'http_status' => $response->status(),
        ]);

        return $decoded;
    }

    /**
     * @param  string|list<string>  $waybills
     */
    public function shippingLabelPdf(string|array $waybills): array
    {
        $wbns = is_array($waybills) ? implode(',', $waybills) : $waybills;

        if ($this->mockEnabled()) {
            return $this->mockShippingLabelPdfResponse($wbns);
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(60)
            ->get($this->url('packing_slip'), [
                'wbns' => $wbns,
                'pdf' => 'true',
                'pdf_size' => (string) config('delhivery.label_pdf_size', '4R'),
            ]);

        return $this->decodeResponse($response, 'Delhivery shipping label generation failed');
    }

    /**
     * @param  array{pickup_location: string, pickup_date: string, pickup_time: string, expected_package_count: int}  $payload
     */
    public function createPickupRequest(array $payload): array
    {
        if ($this->mockEnabled()) {
            return $this->mockCreatePickupRequestResponse($payload);
        }

        $url = $this->url('pickup_request');

        $response = Http::withHeaders([
            ...$this->headers(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->post($url, $payload);

        try {
            return $this->decodeResponse($response, 'Delhivery pickup request creation failed', [201]);
        } catch (DomainException $e) {
            Log::channel('thirdparty')->error('Delhivery pickup request HTTP error', [
                'url' => $url,
                'payload' => $payload,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw $e;
        }
    }

    public function downloadBinary(string $url): string
    {
        $response = Http::timeout(60)->get($url);

        if (!$response->successful()) {
            throw new DomainException('Failed to download Delhivery shipping label PDF');
        }

        $body = $response->body();

        if ($body === '' || !str_starts_with($body, '%PDF')) {
            throw new DomainException('Delhivery shipping label download did not return a valid PDF');
        }

        return $body;
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
                        'PromisedDeliveryDate' => now()->addDays(4)->endOfDay()->format('Y-m-d\TH:i:s'),
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

    private function mockTrackByOrderReferenceResponse(string $orderReference): array
    {
        return [
            'mock' => true,
            'ShipmentData' => [
                [
                    'Shipment' => [
                        'AWB' => 'MOCK' . substr(md5($orderReference), 0, 10),
                        'OrderID' => $orderReference,
                        'Status' => [
                            'Status' => 'Manifested',
                            'StatusLocation' => 'Local Mock',
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

    private function mockShippingLabelPdfResponse(string $waybill): array
    {
        return [
            'mock' => true,
            'packages' => [
                [
                    'wbn' => $waybill,
                    'waybill' => $waybill,
                    'pdf_download_link' => null,
                ],
            ],
            'packages_found' => 1,
        ];
    }

    /**
     * @param  array{pickup_location: string, pickup_date: string, pickup_time: string, expected_package_count: int}  $payload
     */
    private function mockCreatePickupRequestResponse(array $payload): array
    {
        return [
            'mock' => true,
            'success' => true,
            'pickup_id' => 'MOCK-PUR-' . now()->format('YmdHis'),
            'pickup_location_name' => $payload['pickup_location'],
            'pickup_date' => $payload['pickup_date'],
            'pickup_time' => $payload['pickup_time'],
            'expected_package_count' => $payload['expected_package_count'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mockExpectedTatResponse(string $originPin, string $destinationPin): array
    {
        return [
            'mock' => true,
            'success' => true,
            'origin_pin' => $originPin,
            'destination_pin' => $destinationPin,
            'tat' => 4,
            'expected_delivery_date' => now()->addDays(4)->toDateString(),
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

    private function decodeResponse(Response $response, string $defaultMessage, array $successStatuses = []): array
    {
        $status = $response->status();
        $body = trim($response->body());
        $payload = $response->json();

        if (!is_array($payload)) {
            $payload = $body !== '' ? ['raw' => $body] : [];
        }

        $isSuccessful = $response->successful()
            || in_array($status, $successStatuses, true);

        if (!$isSuccessful) {
            throw new DomainException($this->responseErrorMessage($payload, $body, $defaultMessage, $status));
        }

        if (($payload['success'] ?? null) === false) {
            throw new DomainException($this->responseErrorMessage($payload, $body, $defaultMessage, $status));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function responseErrorMessage(array $payload, string $body, string $defaultMessage, int $status): string
    {
        $message = $payload['error']['message']
            ?? $payload['error']['description']
            ?? $payload['error']
            ?? $payload['rmk']
            ?? $payload['message']
            ?? ($payload['raw'] ?? null);

        if (!is_string($message) || trim($message) === '') {
            $message = $body !== '' ? $body : $defaultMessage;
        }

        return "{$message} (HTTP {$status})";
    }
}
