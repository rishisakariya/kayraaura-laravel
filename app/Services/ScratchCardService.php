<?php

namespace App\Services;

use App\Models\Order;
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

        $existing = $this->findActiveCoupon($user);

        if ($existing) {
            return $existing;
        }

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
            // Self-heal: if the coupon was redeemed for an order that has since been
            // cancelled, free it up so the customer can use it again.
            $redeemedOrder = $coupon->order_id ? Order::find($coupon->order_id) : null;

            if ($redeemedOrder && $redeemedOrder->status === 'cancelled') {
                $this->releaseForOrder($redeemedOrder);
                $coupon->refresh();
            } else {
                throw new DomainException('This scratch card coupon has already been redeemed.');
            }
        }

        $isUsedOnActiveOrder = $this->ordersReservingCoupon($user)
            ->where('scratch_coupon_code', $coupon->code)
            ->exists();

        if ($isUsedOnActiveOrder) {
            throw new DomainException('This scratch card coupon is already applied to an active order.');
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
        $codesOnActiveOrders = $this->ordersReservingCoupon($user)
            ->whereNotNull('scratch_coupon_code')
            ->pluck('scratch_coupon_code');

        return ScratchCardCoupon::query()
            ->where('user_id', $user->id)
            ->where('is_redeemed', false)
            ->when(
                $codesOnActiveOrders->isNotEmpty(),
                fn ($query) => $query->whereNotIn('code', $codesOnActiveOrders)
            )
            ->latest('id')
            ->first();
    }

    /**
     * Orders that genuinely reserve a scratch coupon (and so should hide it / block re-use).
     *
     * Online orders awaiting payment do NOT reserve the coupon: the coupon is only
     * consumed when the payment succeeds (the order is redeemed at that point). This
     * keeps the same coupon available to the customer while an online payment is still
     * pending or has failed.
     */
    private function ordersReservingCoupon(User $user)
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->whereNotIn('payment_status', ['failed'])
            ->where(function ($query) {
                $query->where('payment_method', '!=', 'online')
                    ->orWhere('payment_status', '!=', 'pending');
            });
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
        $coupon = ScratchCardCoupon::query()
            ->where('code', strtoupper(trim($code)))
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (!$coupon) {
            throw new DomainException('Invalid scratch card coupon code.');
        }

        if ($coupon->is_redeemed) {
            if ($orderId !== null && (int) $coupon->order_id === $orderId) {
                return $coupon;
            }

            throw new DomainException('This scratch card coupon has already been redeemed.');
        }

        $coupon->update([
            'is_redeemed' => true,
            'redeemed_at' => now(),
            'order_id' => $orderId,
            'discount_amount' => $discountAmount,
        ]);

        return $coupon->refresh();
    }

    public function releaseForOrder(Order $order): void
    {
        if (empty($order->scratch_coupon_code)) {
            return;
        }

        ScratchCardCoupon::query()
            ->where('code', strtoupper(trim($order->scratch_coupon_code)))
            ->where('user_id', $order->user_id)
            ->where('order_id', $order->id)
            ->where('is_redeemed', true)
            ->update([
                'is_redeemed' => false,
                'redeemed_at' => null,
                'order_id' => null,
                'discount_amount' => null,
            ]);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (ScratchCardCoupon::query()->where('code', $code)->exists());

        return $code;
    }
}
