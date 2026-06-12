<?php

namespace App\Services\Delhivery;

use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DelhiveryClient
{
    public function createShipment(array $payload): array
    {
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
        $response = Http::asForm()
            ->withHeaders($this->headers())
            ->timeout(30)
            ->post($this->url('cancel'), [
                'waybill' => $waybill,
                'cancellation' => 'true',
            ]);

        return $this->decodeResponse($response, 'Delhivery shipment cancellation failed');
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
