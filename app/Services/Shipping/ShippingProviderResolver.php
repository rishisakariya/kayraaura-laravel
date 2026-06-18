<?php

namespace App\Services\Shipping;

use App\Models\DelhiverySetting;
use App\Models\OrderShipment;
use DomainException;

class ShippingProviderResolver
{
    public function isDelhiveryEnabled(): bool
    {
        return (bool) config('delhivery.enabled');
    }

    public function isShiprocketEnabled(): bool
    {
        return (bool) config('shiprocket.enabled');
    }

    public function activeProvider(): string
    {
        if ($this->isDelhiveryEnabled() && $this->isShiprocketEnabled()) {
            throw new DomainException('Only one shipping provider can be enabled at a time.');
        }

        if ($this->isShiprocketEnabled()) {
            return OrderShipment::PROVIDER_SHIPROCKET;
        }

        if ($this->isDelhiveryEnabled()) {
            return OrderShipment::PROVIDER_DELHIVERY;
        }

        throw new DomainException('No shipping provider is enabled.');
    }

    public function pickupLocation(): string
    {
        if ($this->activeProvider() === OrderShipment::PROVIDER_SHIPROCKET) {
            return (string) config('shiprocket.pickup_location');
        }

        return (string) DelhiverySetting::current()->pickup_location;
    }
}
