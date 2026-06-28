<?php

namespace App\Models;

class DelhiverySetting
{
    public function __construct(
        public readonly string $client_name,
        public readonly string $pickup_location,
        public readonly string $seller_address,
        public readonly ?string $seller_gst_tin,
        public readonly ?string $default_hsn_code,
        public readonly int $default_length_cm,
        public readonly int $default_width_cm,
        public readonly int $default_height_cm,
    ) {
    }

    public static function current(): self
    {
        return new self(
            client_name: (string) config('delhivery.client_name', ''),
            pickup_location: (string) config('delhivery.pickup_location', ''),
            seller_address: (string) config('delhivery.seller_address', ''),
            seller_gst_tin: config('delhivery.seller_gst_tin'),
            default_hsn_code: config('delhivery.default_hsn_code'),
            default_length_cm: (int) config('delhivery.default_length_cm', 10),
            default_width_cm: (int) config('delhivery.default_width_cm', 10),
            default_height_cm: (int) config('delhivery.default_height_cm', 5),
        );
    }

    public function toArray(): array
    {
        return [
            'client_name' => $this->client_name,
            'pickup_location' => $this->pickup_location,
            'seller_address' => $this->seller_address,
            'seller_gst_tin' => $this->seller_gst_tin,
            'default_hsn_code' => $this->default_hsn_code,
            'default_length_cm' => $this->default_length_cm,
            'default_width_cm' => $this->default_width_cm,
            'default_height_cm' => $this->default_height_cm,
        ];
    }
}
