<?php

namespace App\Services\Delhivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class DelhiveryShipmentErrorClassifier
{
    public function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response?->status();

            return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection')
            || str_contains($message, 'temporarily unavailable')
            || preg_match('/\(http (408|425|429|500|502|503|504)\)/', $message) === 1;
    }

    public function isRecoverableDuplicate(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'same address')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'already exists')
            || str_contains($message, 'already manifested')
            || str_contains($message, 'shipment already')
            || str_contains($message, 'order already');
    }

    public function isPermanent(Throwable $exception): bool
    {
        if ($this->isRetryable($exception)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        if ($this->isRecoverableDuplicate($message)) {
            return false;
        }

        return str_contains($message, 'token')
            || str_contains($message, 'unauthorized')
            || str_contains($message, '(http 401)')
            || str_contains($message, '(http 403)')
            || str_contains($message, 'not configured')
            || str_contains($message, 'weight is missing')
            || str_contains($message, 'not serviceable');
    }
}
