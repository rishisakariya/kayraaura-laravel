<?php

namespace App\Services;

use App\Models\ScratchCardCoupon;
use App\Models\ScratchCardSetting;
use App\Models\User;
use DomainException;
use Illuminate\Support\Str;

class ScratchCardService
{
    public function settings(): ScratchCardSetting
    {
        return ScratchCardSetting::current();
    }

    public function isActive(): bool
    {
        return $this->settings()->is_active;
    }

    public function assertActive(): void
    {
        if (!$this->isActive()) {
            throw new DomainException('Scratch card feature is currently disabled.');
        }
    }

    public function scratch(User $user): ScratchCardCoupon
    {
        $this->assertActive();

        $setting = $this->settings();
        $discountPercent = random_int(
            $setting->min_discount_percent,
            $setting->max_discount_percent
        );

        return ScratchCardCoupon::create([
            'user_id' => $user->id,
            'code' => $this->generateUniqueCode(),
            'discount_percent' => $discountPercent,
        ]);
    }

    public function findRedeemableCoupon(User $user, string $code): ScratchCardCoupon
    {
        $this->assertActive();

        $coupon = ScratchCardCoupon::query()
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (!$coupon) {
            throw new DomainException('Invalid scratch card coupon code.');
        }

        if ($coupon->user_id !== $user->id) {
            throw new DomainException('This coupon does not belong to your account.');
        }

        if ($coupon->is_redeemed) {
            throw new DomainException('This scratch card coupon has already been redeemed.');
        }

        return $coupon;
    }

    public function applyCouponToCheckout(User $user, array $checkout, ?string $code): array
    {
        if (!$this->isActive()) {
            return $checkout;
        }

        if (empty($code)) {
            $coupon = $this->findActiveCoupon($user);

            if (!$coupon) {
                return $checkout;
            }

            $discounted = $this->applyDiscount($checkout, $coupon);
            $discounted['scratch_coupon'] = $coupon;

            return $discounted;
        }

        $coupon = $this->findRedeemableCoupon($user, $code);
        $discounted = $this->applyDiscount($checkout, $coupon);
        $discounted['scratch_coupon'] = $coupon;

        return $discounted;
    }

    public function findActiveCoupon(User $user): ?ScratchCardCoupon
    {
        return ScratchCardCoupon::query()
            ->where('user_id', $user->id)
            ->where('is_redeemed', false)
            ->latest('id')
            ->first();
    }

    public function applyDiscount(array $checkout, ScratchCardCoupon $coupon): array
    {
        $totalAmount = (float) $checkout['total_amount'];
        $discountAmount = round($totalAmount * ($coupon->discount_percent / 100), 2);
        $finalTotalAmount = round(max($totalAmount - $discountAmount, 0), 2);

        return [
            ...$checkout,
            'coupon_code' => $coupon->code,
            'discount_percent' => $coupon->discount_percent,
            'discount_amount' => $discountAmount,
            'final_total_amount' => $finalTotalAmount,
        ];
    }

    public function redeem(User $user, string $code, ?int $orderId = null, ?float $discountAmount = null): ScratchCardCoupon
    {
        $coupon = $this->findRedeemableCoupon($user, $code);

        $coupon->update([
            'is_redeemed' => true,
            'redeemed_at' => now(),
            'order_id' => $orderId,
            'discount_amount' => $discountAmount,
        ]);

        return $coupon->refresh();
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (ScratchCardCoupon::query()->where('code', $code)->exists());

        return $code;
    }
}
