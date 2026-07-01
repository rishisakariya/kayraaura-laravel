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

        if ($driver === 'whatsapp') {
            Log::channel('thirdparty')->info('WhatsApp text message skipped; OTP messages must use approved templates', [
                'mobile' => $this->formatWhatsAppMobile($mobile),
                'message' => $message,
            ]);

            return;
        }

        Log::channel('thirdparty')->info('SMS message queued', [
            'mobile' => $mobile,
            'message' => $message,
        ]);
    }

    public function sendOtp(string $mobile, string $otp, string $purpose, int $expiryMinutes): void
    {
        $driver = config('services.sms.driver', 'log');

        if ($driver === 'whatsapp') {
            $this->sendViaWhatsApp($mobile, $otp, $purpose, $expiryMinutes);

            return;
        }

        if ($driver !== 'msg91') {
            Log::channel('thirdparty')->info('SMS OTP queued', [
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
        $flowId = $this->flowIdFor();

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
            Log::channel('thirdparty')->warning('MSG91 SMS send failed', [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);

            throw new DomainException('Failed to send SMS OTP');
        }

        Log::channel('thirdparty')->info('MSG91 SMS sent successfully', [
            'mobile' => $this->formatMsg91Mobile($mobile),
            'purpose' => $purpose,
            'status' => $response->status(),
        ]);
    }

    private function sendViaWhatsApp(string $mobile, string $otp, string $purpose, int $expiryMinutes): void
    {
        $accessToken = config('services.sms.whatsapp.access_token');
        $phoneNumberId = config('services.sms.whatsapp.phone_number_id');
        $templateName = $this->whatsAppTemplateNameFor($purpose);

        if (!$accessToken || !$phoneNumberId || !$templateName) {
            throw new DomainException('WhatsApp Cloud API credentials or template are not configured');
        }

        $components = $this->whatsAppTemplateComponents($otp, $purpose, $expiryMinutes);

        $response = Http::timeout(15)
            ->withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->post($this->whatsAppMessagesEndpoint(), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $this->formatWhatsAppMobile($mobile),
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => config('services.sms.whatsapp.language', 'en_US'),
                    ],
                    'components' => $components,
                ],
            ]);

        if (!$response->successful()) {
            Log::channel('thirdparty')->warning('WhatsApp OTP send failed', [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
                'template' => $templateName,
                'components' => $components,
            ]);

            throw new DomainException('Failed to send WhatsApp OTP');
        }

        Log::channel('thirdparty')->info('WhatsApp OTP sent successfully', [
            'mobile' => $this->formatWhatsAppMobile($mobile),
            'purpose' => $purpose,
            'template' => $templateName,
            'status' => $response->status(),
        ]);
    }

    private function flowIdFor(): ?string
    {
        return config('services.sms.msg91.flow_id');
    }

    private function formatMsg91Mobile(string $mobile): string
    {
        $mobile = preg_replace('/\D+/', '', $mobile) ?? $mobile;
        $countryCode = (string) config('services.sms.msg91.country_code', '91');

        return str_starts_with($mobile, $countryCode) ? $mobile : $countryCode . $mobile;
    }

    private function formatWhatsAppMobile(string $mobile): string
    {
        $mobile = trim($mobile);

        if (str_starts_with($mobile, '+')) {
            return '+' . (preg_replace('/\D+/', '', $mobile) ?? ltrim($mobile, '+'));
        }

        $mobile = preg_replace('/\D+/', '', $mobile) ?? $mobile;
        $countryCode = (string) config('services.sms.whatsapp.country_code', '91');

        return '+' . (str_starts_with($mobile, $countryCode) ? $mobile : $countryCode . $mobile);
    }

    private function whatsAppMessagesEndpoint(): string
    {
        $baseUrl = rtrim((string) config('services.sms.whatsapp.base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('services.sms.whatsapp.version', 'v25.0'), '/');
        $phoneNumberId = config('services.sms.whatsapp.phone_number_id');

        return "{$baseUrl}/{$version}/{$phoneNumberId}/messages";
    }

    private function whatsAppTemplateNameFor(string $purpose): ?string
    {
        $template = config("services.sms.whatsapp.templates.{$purpose}");

        if (is_string($template) && $template !== '') {
            return $template;
        }

        $default = config('services.sms.whatsapp.templates.default');

        return is_string($default) && $default !== '' ? $default : null;
    }

    private function whatsAppTemplateComponents(string $otp, string $purpose, int $expiryMinutes): array
    {
        $parameters = [
            'code' => $otp,
            'otp' => $otp,
            'text' => $this->purposeLabel($purpose),
            'purpose' => $this->purposeLabel($purpose),
            'expiry' => (string) $expiryMinutes,
        ];

        $bodyParameters = collect(config('services.sms.whatsapp.body_parameters', ['code', 'text']))
            ->map(function (string $parameter) use ($parameters): array {
                $value = $parameters[$parameter] ?? '';

                if ($value === '') {
                    throw new DomainException(
                        "WhatsApp template parameter \"{$parameter}\" is empty; check WHATSAPP_CLOUD_BODY_PARAMETERS"
                    );
                }

                return [
                    'type' => 'text',
                    'text' => $value,
                ];
            })
            ->values()
            ->all();

        $components = [
            [
                'type' => 'body',
                'parameters' => $bodyParameters,
            ],
        ];

        if (config('services.sms.whatsapp.button.enabled', true)) {
            $components[] = [
                'type' => 'button',
                'sub_type' => config('services.sms.whatsapp.button.sub_type', 'url'),
                'index' => (string) config('services.sms.whatsapp.button.index', '0'),
                'parameters' => [
                    [
                        'type' => 'text',
                        'text' => $otp,
                    ],
                ],
            ];
        }

        return $components;
    }

    private function purposeLabel(string $purpose): string
    {
        // Meta auth template {{text}} body variable is limited to 15 characters.
        return match ($purpose) {
            OtpService::PURPOSE_COD_ORDER => 'COD order',
            OtpService::PURPOSE_FORGOT_PASSWORD => 'Login',
            default => 'verification',
        };
    }
}
