<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\RazorpayPaymentLog;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\WebSetting;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class CheckoutService
{
    private const COD_CHARGE_RATE = 0.10;

    private const FIRST_ORDER_DISCOUNT_AMOUNT = 50.0;

    private const ONLINE_PAYMENT_DISCOUNT_RATE = 0.10;

    public function buildCheckout(User $user, array $payload, bool $lockProductSizes = false): array
    {
        $address = UserAddress::where('user_id', $user->id)->find($payload['address_id']);

        if (!$address) {
            throw new DomainException('Selected address was not found');
        }

        $items = $payload['checkout_type'] === 'cart'
            ? $this->cartItems($user, $lockProductSizes)
            : $this->buyNowItems($payload, $lockProductSizes);

        $itemsSubtotal = round($items->sum('total'), 2);
        $buyTwoGetOneFreeEnabled = WebSetting::current()->buy_two_get_one_free_enabled;
        $buyTwoGetOneDiscountAmount = $buyTwoGetOneFreeEnabled
            ? $this->calculateBuyTwoGetOneDiscount($items)
            : 0.0;
        $subtotal = round(max($itemsSubtotal - $buyTwoGetOneDiscountAmount, 0), 2);
        $taxAmount = round($subtotal * 0.18, 2);
        $shippingAmount = $subtotal > 1000 ? 0.0 : 50.0;
        $baseTotal = round($subtotal + $taxAmount + $shippingAmount, 2);
        $firstOrderDiscountEligible = $this->userQualifiesForFirstOrderDiscount($user);
        $firstOrderDiscountAmount = $firstOrderDiscountEligible
            ? min(self::FIRST_ORDER_DISCOUNT_AMOUNT, $baseTotal)
            : 0.0;
        $baseTotalAfterFirstOrderDiscount = round(max($baseTotal - $firstOrderDiscountAmount, 0), 2);
        $isCod = ($payload['payment_method'] ?? null) === 'cod';
        $isOnline = ($payload['payment_method'] ?? null) === 'online';
        $onlinePaymentDiscountAmount = $isOnline
            ? round($baseTotalAfterFirstOrderDiscount * self::ONLINE_PAYMENT_DISCOUNT_RATE, 2)
            : 0.0;
        $baseTotalAfterOnlineDiscount = round(max($baseTotalAfterFirstOrderDiscount - $onlinePaymentDiscountAmount, 0), 2);
        $codCharge = $isCod ? round($baseTotalAfterFirstOrderDiscount * self::COD_CHARGE_RATE, 2) : 0.0;
        $totalAmount = $isCod
            ? round($baseTotalAfterFirstOrderDiscount + $codCharge, 2)
            : $baseTotalAfterOnlineDiscount;

        return [
            'address' => $address,
            'items' => $items,
            'items_subtotal' => $itemsSubtotal,
            'buy_two_get_one_free_enabled' => $buyTwoGetOneFreeEnabled,
            'buy_two_get_one_discount_amount' => $buyTwoGetOneDiscountAmount,
            'first_order_discount_eligible' => $firstOrderDiscountEligible,
            'first_order_discount_amount' => $firstOrderDiscountAmount,
            'online_payment_discount_percent' => $isOnline ? (int) (self::ONLINE_PAYMENT_DISCOUNT_RATE * 100) : null,
            'online_payment_discount_amount' => $onlinePaymentDiscountAmount,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'shipping_amount' => $shippingAmount,
            'cod_charge' => $codCharge,
            'total_amount' => $totalAmount,
        ];
    }

    public function createOrder(User $user, array $payload, array $checkout): Order
    {
        $firstOrderDiscountAmount = (float) ($checkout['first_order_discount_amount'] ?? 0);

        if ($firstOrderDiscountAmount > 0 && !$this->userQualifiesForFirstOrderDiscount($user)) {
            throw new DomainException('First order discount is no longer available');
        }

        $addressSnapshot = $checkout['address']->toSnapshot();

        $order = Order::create([
            'user_id' => $user->id,
            'address_id' => $checkout['address']->id,
            'order_number' => Order::generateOrderNumber(),
            'checkout_type' => $payload['checkout_type'],
            'status' => $payload['payment_method'] === 'cod' ? 'pending_admin_confirmation' : 'pending',
            'subtotal' => $checkout['subtotal'],
            'tax_amount' => $checkout['tax_amount'],
            'shipping_amount' => $checkout['shipping_amount'],
            'cod_charge' => $checkout['cod_charge'],
            'buy_two_get_one_discount_amount' => $checkout['buy_two_get_one_discount_amount'] ?? 0,
            'first_order_discount_amount' => $firstOrderDiscountAmount,
            'online_payment_discount_amount' => $checkout['online_payment_discount_amount'] ?? 0,
            'scratch_coupon_code' => $checkout['coupon_code'] ?? null,
            'discount_percent' => $checkout['discount_percent'] ?? null,
            'discount_amount' => $checkout['discount_amount'] ?? 0,
            'total_amount' => $checkout['final_total_amount'] ?? $checkout['total_amount'],
            'payment_method' => $payload['payment_method'],
            'payment_status' => 'pending',
            'cod_verified_at' => $payload['payment_method'] === 'cod' ? now() : null,
            'shipping_address' => $addressSnapshot,
            'billing_address' => $addressSnapshot,
            'notes' => $payload['notes'] ?? null,
        ]);

        foreach ($checkout['items'] as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'product_size_id' => $item['product_size_id'],
                'product_name' => $item['product_name'],
                'product_slug' => $item['product_slug'],
                'size_text' => $item['size_text'],
                'size_price' => $item['size_price'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $item['total'],
            ]);
        }

        return $order;
    }

    public function deductStockForOrder(Order $order): void
    {
        $order->loadMissing('orderItems.product');

        foreach ($order->orderItems as $item) {
            $productSize = ProductSize::whereKey($item->product_size_id)->lockForUpdate()->first();

            if (!$productSize || !$item->product || !$item->product->is_active) {
                throw new DomainException('Product not found or inactive');
            }

            if ($item->product->track_stock && $productSize->quantity < $item->quantity) {
                throw new DomainException('Insufficient stock available for selected size');
            }

            if ($item->product->track_stock) {
                $productSize->decrement('quantity', $item->quantity);
            }
        }
    }

    public function clearCartIfNeeded(Order $order): void
    {
        if ($order->checkout_type === 'cart') {
            Cart::forUser($order->user_id)->delete();
        }
    }

    public function restoreStockForOrder(Order $order): void
    {
        $order->loadMissing('orderItems.product');

        foreach ($order->orderItems as $item) {
            $product = $item->product;
            $productSize = ProductSize::whereKey($item->product_size_id)->lockForUpdate()->first();

            if ($product && $productSize && $product->track_stock) {
                $productSize->increment('quantity', $item->quantity);
            }
        }
    }

    public function createRazorpayOrder(Order $order): array
    {
        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');

        if (!$key || !$secret) {
            throw new DomainException('Razorpay credentials are not configured');
        }

        $requestPayload = [
            'amount' => (int) round(((float) $order->total_amount) * 100),
            'currency' => 'INR',
            'receipt' => $order->order_number,
            'notes' => [
                'local_order_id' => (string) $order->id,
                'order_number' => $order->order_number,
            ],
        ];

        $response = Http::withBasicAuth($key, $secret)
            ->post('https://api.razorpay.com/v1/orders', $requestPayload);

        $responsePayload = $response->json() ?? [];

        RazorpayPaymentLog::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'razorpay_order_id' => $responsePayload['id'] ?? null,
            'event_type' => 'order.create',
            'status' => $response->successful() ? 'created' : 'failed',
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'error_code' => $responsePayload['error']['code'] ?? null,
            'error_description' => $responsePayload['error']['description'] ?? null,
        ]);

        if (!$response->successful() || empty($responsePayload['id'])) {
            throw new DomainException($responsePayload['error']['description'] ?? 'Failed to create Razorpay order');
        }

        $order->update(['razorpay_order_id' => $responsePayload['id']]);

        return [
            'key' => $key,
            'order_id' => $responsePayload['id'],
            'amount' => $requestPayload['amount'],
            'currency' => $requestPayload['currency'],
            'name' => config('app.name'),
            'description' => 'Order ' . $order->order_number,
            'prefill' => [
                'name' => $order->shipping_address['name'] ?? null,
                'email' => $order->shipping_address['email'] ?? null,
                'contact' => $order->shipping_address['phone'] ?? null,
            ],
        ];
    }

    public function verifyPaymentSignature(string $razorpayOrderId, string $paymentId, string $signature): bool
    {
        $secret = config('services.razorpay.secret');

        if (!$secret) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $razorpayOrderId . '|' . $paymentId, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        $secret = config('services.razorpay.webhook_secret');

        if (!$secret || !$signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function fetchRazorpayPayment(string $paymentId): array
    {
        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');

        if (!$key || !$secret) {
            throw new DomainException('Razorpay credentials are not configured');
        }

        $response = Http::withBasicAuth($key, $secret)
            ->get("https://api.razorpay.com/v1/payments/{$paymentId}");

        $payload = $response->json() ?? [];

        if (!$response->successful()) {
            throw new DomainException($payload['error']['description'] ?? 'Unable to verify Razorpay payment');
        }

        return $payload;
    }

    public function refundRazorpayPayment(Order $order, string $reason = 'order_cancelled'): array
    {
        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');

        if (!$key || !$secret) {
            throw new DomainException('Razorpay credentials are not configured');
        }

        if (!$order->razorpay_payment_id) {
            throw new DomainException('Razorpay payment id is missing for this order');
        }

        $requestPayload = [
            'amount' => (int) round(((float) $order->total_amount) * 100),
            'speed' => 'normal',
            'notes' => [
                'local_order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'reason' => $reason,
            ],
        ];

        $response = Http::withBasicAuth($key, $secret)
            ->post("https://api.razorpay.com/v1/payments/{$order->razorpay_payment_id}/refund", $requestPayload);

        $responsePayload = $response->json() ?? [];

        RazorpayPaymentLog::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'razorpay_order_id' => $order->razorpay_order_id,
            'razorpay_payment_id' => $order->razorpay_payment_id,
            'event_type' => 'refund.create',
            'status' => $response->successful() ? ($responsePayload['status'] ?? 'refunded') : 'failed',
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'error_code' => $responsePayload['error']['code'] ?? null,
            'error_description' => $responsePayload['error']['description'] ?? null,
        ]);

        if (!$response->successful()) {
            throw new DomainException($responsePayload['error']['description'] ?? 'Failed to refund Razorpay payment');
        }

        return $responsePayload;
    }

    public function assertPaymentAmountMatches(Order $order, array $paymentPayload): void
    {
        $expectedAmount = (int) round(((float) $order->total_amount) * 100);
        $actualAmount = (int) ($paymentPayload['amount'] ?? 0);

        if ($expectedAmount !== $actualAmount) {
            throw new DomainException('Razorpay payment amount does not match local order total');
        }
    }

    public function markOrderPaid(Order $order, array $paymentData): Order
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

        if ($order->payment_status === 'paid') {
            return $order;
        }

        if ($order->razorpay_order_id && $order->razorpay_order_id !== $paymentData['razorpay_order_id']) {
            throw new DomainException('Razorpay order id does not match local order');
        }

        try {
            $this->deductStockForOrder($order);
        } catch (DomainException $e) {
            $order->update([
                'status' => 'manual_review',
                'payment_status' => 'paid_stock_failed',
                'razorpay_payment_id' => $paymentData['razorpay_payment_id'] ?? $order->razorpay_payment_id,
                'razorpay_signature' => $paymentData['razorpay_signature'] ?? $order->razorpay_signature,
                'paid_at' => now(),
            ]);

            return $order;
        }

        $order->update([
            'status' => 'pending',
            'payment_status' => 'paid',
            'razorpay_payment_id' => $paymentData['razorpay_payment_id'] ?? $order->razorpay_payment_id,
            'razorpay_signature' => $paymentData['razorpay_signature'] ?? $order->razorpay_signature,
            'paid_at' => now(),
        ]);

        $this->clearCartIfNeeded($order);

        return $order;
    }

    public function markOrderPaymentFailed(Order $order, ?string $paymentId = null): Order
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

        if ($order->payment_status !== 'paid') {
            $order->update([
                'payment_status' => 'failed',
                'razorpay_payment_id' => $paymentId ?? $order->razorpay_payment_id,
                'payment_failed_at' => now(),
            ]);
        }

        return $order;
    }

    private function cartItems(User $user, bool $lockProductSizes): Collection
    {
        $cartItems = Cart::forUser($user->id)->get();

        if ($cartItems->isEmpty()) {
            throw new DomainException('Cart is empty');
        }

        return $cartItems->map(function (Cart $cartItem) use ($lockProductSizes) {
            if (!$cartItem->product_size_id) {
                throw new DomainException('Every cart item must have a product size');
            }

            return $this->checkoutItemFromProductSize($cartItem->product_size_id, $cartItem->quantity, $lockProductSizes);
        });
    }

    private function buyNowItems(array $payload, bool $lockProductSizes): Collection
    {
        return collect([
            $this->checkoutItemFromProductSize($payload['product_size_id'], $payload['quantity'], $lockProductSizes),
        ]);
    }

    private function checkoutItemFromProductSize(int $productSizeId, int $quantity, bool $lockProductSizes): array
    {
        $query = ProductSize::with([
            'size',
            'product.category',
            'product.images',
            'product.primaryImage',
            'product.sizes.size',
        ])->whereKey($productSizeId);

        if ($lockProductSizes) {
            $query->lockForUpdate();
        }

        $productSize = $query->first();
        $product = $productSize?->product;

        if (!$productSize || !$product || !$product->is_active) {
            throw new DomainException('Product not found or inactive');
        }

        if ($quantity < 1) {
            throw new DomainException('Quantity must be at least 1');
        }

        if ($product->track_stock && $productSize->quantity < $quantity) {
            throw new DomainException('Insufficient stock available for selected size');
        }

        $price = round((float) $productSize->price, 2);

        return [
            'product_id' => $product->id,
            'product_size_id' => $productSize->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'product' => $product,
            'size_text' => $productSize->size?->name ?? $productSize->size_text,
            'size_price' => $price,
            'quantity' => $quantity,
            'price' => $price,
            'total' => round($price * $quantity, 2),
        ];
    }

    public function userQualifiesForFirstOrderDiscount(User $user): bool
    {
        return !Order::where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->where('payment_status', '!=', 'failed')
            ->exists();
    }

    public function calculateBuyTwoGetOneDiscount(Collection $items): float
    {
        $unitPrices = [];

        foreach ($items as $item) {
            $price = round((float) (data_get($item, 'price') ?? data_get($item, 'size_price') ?? 0), 2);
            $quantity = (int) data_get($item, 'quantity', 0);

            for ($i = 0; $i < $quantity; $i++) {
                $unitPrices[] = $price;
            }
        }

        $freeItemCount = intdiv(count($unitPrices), 3);

        if ($freeItemCount < 1) {
            return 0.0;
        }

        sort($unitPrices, SORT_NUMERIC);

        return round(array_sum(array_slice($unitPrices, 0, $freeItemCount)), 2);
    }
}
