<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function send(string $mobile, string $message): void
    {
        $driver = config('services.sms.driver', 'log');

        if ($driver === 'http') {
            $this->sendViaHttpGateway($mobile, $message);

            return;
        }

        Log::info('SMS message queued', [
            'mobile' => $mobile,
            'message' => $message,
        ]);
    }

    private function sendViaHttpGateway(string $mobile, string $message): void
    {
        $url = config('services.sms.url');
        $token = config('services.sms.token');

        if (!$url || !$token) {
            Log::warning('SMS gateway is not configured; falling back to log driver', [
                'mobile' => $mobile,
                'message' => $message,
            ]);

            return;
        }

        Http::withToken($token)->post($url, [
            'mobile' => $mobile,
            'message' => $message,
        ])->throw();
    }
}
