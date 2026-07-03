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
use Illuminate\Support\Facades\Log;

class CheckoutService
{
    public function __construct(
        private readonly ScratchCardService $scratchCardService,
    ) {
    }

    private const COD_CHARGE_AMOUNT = 50.00;

    private const GST_RATE = 0.03;

    /**
     * Test accounts whose payable total is always forced to a fixed amount (e.g. live payment testing).
     * Email (lowercase) => fixed total amount in ₹.
     */
    private const FIXED_TOTAL_TEST_ACCOUNTS = [
        'ajradadiya12999@gmail.com' => 2.00,
        'rishi.sakriya@gmail.com' => 2.00,
    ];

    public function buildCheckout(User $user, array $payload, bool $lockProductSizes = false): array
    {
        $address = UserAddress::where('user_id', $user->id)->find($payload['address_id']);

        if (!$address) {
            throw new DomainException('Selected address was not found');
        }

        $items = $payload['checkout_type'] === 'cart'
            ? $this->cartItems($user, $lockProductSizes)
            : $this->buyNowItems($payload, $lockProductSizes);

        $pricing = $this->calculatePricingSummary(
            $user,
            $items,
            $payload['payment_method'] ?? null
        );

        return [
            'address' => $address,
            'items' => $items,
            ...$pricing,
        ];
    }

    public function buildCartSummary(User $user, Collection $cartItems, ?string $paymentMethod = null): array
    {
        return $this->calculatePricingSummary($user, $cartItems, $paymentMethod);
    }

    private function calculatePricingSummary(User $user, Collection $items, ?string $paymentMethod = null): array
    {
        $itemsSubtotal = round($items->sum(function ($item): float {
            $total = data_get($item, 'total');

            if ($total !== null) {
                return (float) $total;
            }

            return (int) data_get($item, 'quantity', 0) * (float) (data_get($item, 'size_price') ?? data_get($item, 'price', 0));
        }), 2);
        $webSetting = WebSetting::current();
        $buyTwoGetOneFreeEnabled = $webSetting->buy_two_get_one_free_enabled;
        $buyTwoGetOneDiscountAmount = $buyTwoGetOneFreeEnabled
            ? $this->calculateBuyTwoGetOneDiscount($items)
            : 0.0;
        $subtotal = round(max($itemsSubtotal - $buyTwoGetOneDiscountAmount, 0), 2);
        $taxAmount = round($subtotal * self::GST_RATE, 2);
        $shippingAmount = $subtotal > 1000 ? 0.0 : 50.0;
        $baseTotal = round($subtotal + $taxAmount + $shippingAmount, 2);
        $configuredFirstOrderDiscount = max((float) ($webSetting->first_order_discount_amount ?? 0), 0);
        $configuredOnlinePaymentDiscountPercent = max((int) ($webSetting->online_payment_discount_percent ?? 0), 0);
        $firstOrderDiscountEligible = $this->userQualifiesForFirstOrderDiscount($user);
        $firstOrderDiscountAmount = $firstOrderDiscountEligible
            ? min($configuredFirstOrderDiscount, $baseTotal)
            : 0.0;
        $baseTotalAfterFirstOrderDiscount = round(max($baseTotal - $firstOrderDiscountAmount, 0), 2);
        $isCod = $paymentMethod === 'cod';
        $isOnline = $paymentMethod === 'online';
        $onlinePaymentDiscountAmount = $isOnline
            ? round($baseTotalAfterFirstOrderDiscount * ($configuredOnlinePaymentDiscountPercent / 100), 2)
            : 0.0;
        $baseTotalAfterOnlineDiscount = round(max($baseTotalAfterFirstOrderDiscount - $onlinePaymentDiscountAmount, 0), 2);
        $codCharge = $isCod ? self::COD_CHARGE_AMOUNT : 0.0;
        $totalAmount = $isCod
            ? round($baseTotalAfterFirstOrderDiscount + $codCharge, 2)
            : $baseTotalAfterOnlineDiscount;

        $fixedTestTotal = self::FIXED_TOTAL_TEST_ACCOUNTS[strtolower((string) $user->email)] ?? null;

        if ($fixedTestTotal !== null) {
            $itemsSubtotal = 0.0;
            $buyTwoGetOneDiscountAmount = 0.0;
            $firstOrderDiscountAmount = 0.0;
            $onlinePaymentDiscountAmount = 0.0;
            $subtotal = 0.0;
            $taxAmount = 0.0;
            $shippingAmount = 0.0;
            $codCharge = 0.0;
            $totalAmount = (float) $fixedTestTotal;
        }

        return [
            'items_subtotal' => $itemsSubtotal,
            'buy_two_get_one_free_enabled' => $buyTwoGetOneFreeEnabled,
            'buy_two_get_one_discount_amount' => $buyTwoGetOneDiscountAmount,
            'first_order_discount_eligible' => $firstOrderDiscountEligible,
            'first_order_discount_amount' => $firstOrderDiscountAmount,
            'online_payment_discount_percent' => $isOnline ? $configuredOnlinePaymentDiscountPercent : null,
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
            'status' => 'pending',
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

        Log::channel('thirdparty')->info('Payment flow: order created', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'payment_method' => $order->payment_method,
            'total_amount' => $order->total_amount,
        ]);

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

            $deducted = ProductSize::query()
                ->whereKey($productSize->id)
                ->where('quantity', '>=', $item->quantity)
                ->decrement('quantity', $item->quantity);

            if ($deducted === 0) {
                throw new DomainException('Insufficient stock available for selected size');
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
            $this->restoreStockForOrderItem($item, $item->quantity);
        }
    }

    /**
     * @param  array<int, array{order_item_id: int, quantity: int}>  $returnItems
     */
    public function restoreStockForReturnedItems(Order $order, array $returnItems): void
    {
        $order->loadMissing('orderItems.product');
        $itemsById = $order->orderItems->keyBy('id');

        foreach ($returnItems as $returnItem) {
            $orderItem = $itemsById->get($returnItem['order_item_id'] ?? null);

            if (!$orderItem) {
                continue;
            }

            $this->restoreStockForOrderItem($orderItem, (int) ($returnItem['quantity'] ?? 0));
        }
    }

    private function restoreStockForOrderItem(OrderItem $item, int $quantity): void
    {
        if ($quantity < 1) {
            return;
        }

        $product = $item->product;
        $productSize = ProductSize::whereKey($item->product_size_id)->lockForUpdate()->first();

        if ($product && $productSize) {
            $productSize->increment('quantity', $quantity);
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

        Log::channel('thirdparty')->info('Payment flow: Razorpay order create requested', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'amount_paise' => $requestPayload['amount'],
        ]);

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
            Log::channel('thirdparty')->error('Payment flow: Razorpay order create failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $responsePayload['error']['description'] ?? 'Failed to create Razorpay order',
            ]);

            throw new DomainException($responsePayload['error']['description'] ?? 'Failed to create Razorpay order');
        }

        $order->update(['razorpay_order_id' => $responsePayload['id']]);

        Log::channel('thirdparty')->info('Payment flow: Razorpay order created', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'razorpay_order_id' => $responsePayload['id'],
        ]);

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

    public function refundRazorpayPayment(Order $order, string $reason = 'order_cancelled', ?float $amount = null): array
    {
        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');

        if (!$key || !$secret) {
            throw new DomainException('Razorpay credentials are not configured');
        }

        if (!$order->razorpay_payment_id) {
            throw new DomainException('Razorpay payment id is missing for this order');
        }

        $refundAmount = $amount ?? (float) $order->total_amount;

        if ($refundAmount <= 0) {
            throw new DomainException('Refund amount must be greater than zero');
        }

        Log::channel('thirdparty')->info('Payment flow: Razorpay refund requested', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'razorpay_payment_id' => $order->razorpay_payment_id,
            'refund_amount' => $refundAmount,
            'reason' => $reason,
        ]);

        $requestPayload = [
            'amount' => (int) round($refundAmount * 100),
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
            Log::channel('thirdparty')->error('Payment flow: Razorpay refund failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'razorpay_payment_id' => $order->razorpay_payment_id,
                'refund_amount' => $refundAmount,
                'reason' => $reason,
                'error' => $responsePayload['error']['description'] ?? 'Failed to refund Razorpay payment',
            ]);

            throw new DomainException($responsePayload['error']['description'] ?? 'Failed to refund Razorpay payment');
        }

        Log::channel('thirdparty')->info('Payment flow: Razorpay refund processed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'razorpay_payment_id' => $order->razorpay_payment_id,
            'refund_id' => $responsePayload['id'] ?? null,
            'refund_amount' => $refundAmount,
            'reason' => $reason,
            'status' => $responsePayload['status'] ?? null,
        ]);

        return $responsePayload;
    }

    /**
     * Send a RazorpayX UPI payout for COD return refunds.
     *
     * @param  array<string, string>  $notes
     * @return array<string, mixed>
     */
    public function payoutToUpi(
        Order $order,
        string $upiId,
        string $name,
        string $email,
        string $mobile,
        float $amount,
        string $referenceId,
        array $notes = [],
    ): array {
        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        $accountNumber = config('services.razorpay.x_account_number');

        if (!$key || !$secret) {
            throw new DomainException('Razorpay credentials are not configured');
        }

        if (!$accountNumber) {
            throw new DomainException('RazorpayX account number is not configured for UPI payouts');
        }

        if ($amount <= 0) {
            throw new DomainException('Payout amount must be greater than zero');
        }

        Log::channel('thirdparty')->info('Payment flow: UPI payout requested', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'reference_id' => $referenceId,
            'amount' => $amount,
            'notes' => $notes,
        ]);

        $requestPayload = [
            'account_number' => $accountNumber,
            'fund_account' => [
                'account_type' => 'vpa',
                'vpa' => [
                    'address' => $upiId,
                ],
                'contact' => [
                    'name' => $name,
                    'email' => $email,
                    'contact' => $mobile,
                    'type' => 'customer',
                ],
            ],
            'amount' => (int) round($amount * 100),
            'currency' => 'INR',
            'mode' => 'UPI',
            'purpose' => 'refund',
            'queue_if_low_balance' => true,
            'reference_id' => $referenceId,
            'notes' => array_merge([
                'local_order_id' => (string) $order->id,
                'order_number' => $order->order_number,
            ], $notes),
        ];

        $response = Http::withBasicAuth($key, $secret)
            ->post('https://api.razorpay.com/v1/payouts', $requestPayload);

        $responsePayload = $response->json() ?? [];

        RazorpayPaymentLog::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'razorpay_order_id' => $order->razorpay_order_id,
            'razorpay_payment_id' => $responsePayload['id'] ?? null,
            'event_type' => 'payout.create',
            'status' => $response->successful() ? ($responsePayload['status'] ?? 'processing') : 'failed',
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'error_code' => $responsePayload['error']['code'] ?? null,
            'error_description' => $responsePayload['error']['description'] ?? null,
        ]);

        if (!$response->successful()) {
            Log::channel('thirdparty')->error('Payment flow: UPI payout failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reference_id' => $referenceId,
                'amount' => $amount,
                'error' => $responsePayload['error']['description'] ?? 'Failed to process UPI payout',
            ]);

            throw new DomainException($responsePayload['error']['description'] ?? 'Failed to process UPI payout');
        }

        Log::channel('thirdparty')->info('Payment flow: UPI payout processed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payout_id' => $responsePayload['id'] ?? null,
            'reference_id' => $referenceId,
            'amount' => $amount,
            'status' => $responsePayload['status'] ?? null,
        ]);

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
            Log::channel('thirdparty')->info('Payment flow: order already paid, skipping mark paid', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            $this->redeemScratchCouponForPaidOrder($order);

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

            Log::channel('thirdparty')->warning('Payment flow: order paid but stock deduction failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'razorpay_payment_id' => $paymentData['razorpay_payment_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->redeemScratchCouponForPaidOrder($order);

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
        $this->redeemScratchCouponForPaidOrder($order);

        Log::channel('thirdparty')->info('Payment flow: order marked paid', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'razorpay_payment_id' => $order->razorpay_payment_id,
            'razorpay_order_id' => $order->razorpay_order_id,
        ]);

        return $order;
    }

    private function redeemScratchCouponForPaidOrder(Order $order): void
    {
        if (empty($order->scratch_coupon_code)) {
            return;
        }

        $user = User::find($order->user_id);

        if (!$user) {
            return;
        }

        try {
            $this->scratchCardService->redeem(
                $user,
                $order->scratch_coupon_code,
                $order->id,
                $order->discount_amount !== null ? (float) $order->discount_amount : null
            );
        } catch (DomainException $e) {
            // A coupon may already be redeemed (e.g. applied to another order that was
            // paid first). Never let this block a successful payment from completing.
            Log::channel('thirdparty')->warning('Payment flow: scratch coupon redemption skipped for paid order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'scratch_coupon_code' => $order->scratch_coupon_code,
                'error' => $e->getMessage(),
            ]);
        }
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

            Log::channel('thirdparty')->info('Payment flow: order payment marked failed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'razorpay_payment_id' => $paymentId ?? $order->razorpay_payment_id,
            ]);

            $this->scratchCardService->releaseForOrder($order);
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

        if ($productSize->quantity < $quantity) {
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
