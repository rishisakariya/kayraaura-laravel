<?php

namespace App\Services;

use App\Models\MobileOtp;
use DomainException;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public const PURPOSE_FORGOT_PASSWORD = 'forgot_password';
    public const PURPOSE_COD_ORDER = 'cod_order';

    private const EXPIRY_MINUTES = 5;
    private const RESEND_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly SmsService $smsService)
    {
    }

    public function send(string $mobile, string $purpose): void
    {
        $mobile = $this->normalizeMobile($mobile);

        $latestOtp = MobileOtp::where('mobile', $mobile)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest()
            ->first();

        if ($latestOtp?->last_sent_at?->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            throw new DomainException('Please wait before requesting another OTP');
        }

        MobileOtp::where('mobile', $mobile)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = (string) random_int(100000, 999999);

        MobileOtp::create([
            'mobile' => $mobile,
            'purpose' => $purpose,
            'otp_hash' => Hash::make($otp),
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
        ]);

        $this->smsService->sendOtp($mobile, $otp, $purpose, self::EXPIRY_MINUTES);
    }

    public function verifyAndConsume(string $mobile, string $purpose, string $otp): void
    {
        $mobile = $this->normalizeMobile($mobile);

        $mobileOtp = MobileOtp::where('mobile', $mobile)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest()
            ->first();

        if (!$mobileOtp || $mobileOtp->expires_at->isPast()) {
            throw new DomainException('Invalid or expired OTP');
        }

        if ($mobileOtp->attempts >= self::MAX_ATTEMPTS) {
            $mobileOtp->update(['consumed_at' => now()]);

            throw new DomainException('Too many invalid OTP attempts. Please request a new OTP');
        }

        if (!Hash::check($otp, $mobileOtp->otp_hash)) {
            $mobileOtp->increment('attempts');

            throw new DomainException('Invalid or expired OTP');
        }

        $mobileOtp->update([
            'verified_at' => now(),
            'consumed_at' => now(),
        ]);
    }

    public function normalizeMobile(string $mobile): string
    {
        return preg_replace('/[\s\-()]/', '', trim($mobile)) ?? trim($mobile);
    }

}
