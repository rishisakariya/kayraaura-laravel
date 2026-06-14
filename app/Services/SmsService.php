<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function send(string $mobile, string $message): void
    {
        $driver = config('services.sms.driver', 'log');

        if ($driver === 'msg91') {
            $this->sendViaMsg91($mobile, [
                'MESSAGE' => $message,
            ]);

            return;
        }

        Log::info('SMS message queued', [
            'mobile' => $mobile,
            'message' => $message,
        ]);
    }

    public function sendOtp(string $mobile, string $otp, string $purpose, int $expiryMinutes): void
    {
        $driver = config('services.sms.driver', 'log');

        if ($driver !== 'msg91') {
            Log::info('SMS OTP queued', [
                'mobile' => $mobile,
                'otp' => $otp,
                'purpose' => $purpose,
                'expires_in_minutes' => $expiryMinutes,
            ]);

            return;
        }

        $this->sendViaMsg91($mobile, [
            config('services.sms.msg91.variables.otp', 'OTP') => $otp,
            config('services.sms.msg91.variables.purpose', 'PURPOSE') => $this->purposeLabel($purpose),
            config('services.sms.msg91.variables.expiry', 'EXPIRY_MINUTES') => (string) $expiryMinutes,
        ], $purpose);
    }

    private function sendViaMsg91(string $mobile, array $variables, ?string $purpose = null): void
    {
        $authKey = config('services.sms.msg91.auth_key');
        $flowId = $this->flowIdFor($purpose);

        if (!$authKey || !$flowId) {
            throw new DomainException('MSG91 SMS credentials are not configured');
        }

        $recipient = array_merge([
            'mobiles' => $this->formatMsg91Mobile($mobile),
        ], $variables);

        $payload = [
            'flow_id' => $flowId,
            'recipients' => [$recipient],
        ];

        if ($sender = config('services.sms.msg91.sender_id')) {
            $payload['sender'] = $sender;
        }

        $response = Http::timeout(15)
            ->withHeaders([
                'authkey' => $authKey,
                'Content-Type' => 'application/json',
            ])
            ->post(config('services.sms.msg91.endpoint'), $payload);

        if (!$response->successful()) {
            Log::warning('MSG91 SMS send failed', [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);

            throw new DomainException('Failed to send SMS OTP');
        }
    }

    private function flowIdFor(?string $purpose): ?string
    {
        return match ($purpose) {
            OtpService::PURPOSE_FORGOT_PASSWORD => config('services.sms.msg91.forgot_password_flow_id') ?: config('services.sms.msg91.flow_id'),
            OtpService::PURPOSE_COD_ORDER => config('services.sms.msg91.cod_order_flow_id') ?: config('services.sms.msg91.flow_id'),
            default => config('services.sms.msg91.flow_id'),
        };
    }

    private function formatMsg91Mobile(string $mobile): string
    {
        $mobile = preg_replace('/\D+/', '', $mobile) ?? $mobile;
        $countryCode = (string) config('services.sms.msg91.country_code', '91');

        return str_starts_with($mobile, $countryCode) ? $mobile : $countryCode . $mobile;
    }

    private function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            OtpService::PURPOSE_COD_ORDER => 'COD order confirmation',
            OtpService::PURPOSE_FORGOT_PASSWORD => 'password reset',
            default => 'verification',
        };
    }
}
