<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\RazorpayPaymentLog;
use App\Models\User;
use App\Models\UserAddress;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class CheckoutService
{
    public function buildCheckout(User $user, array $payload, bool $lockProductSizes = false): array
    {
        $address = UserAddress::where('user_id', $user->id)->find($payload['address_id']);

        if (!$address) {
            throw new DomainException('Selected address was not found');
        }

        $items = $payload['checkout_type'] === 'cart'
            ? $this->cartItems($user, $lockProductSizes)
            : $this->buyNowItems($payload, $lockProductSizes);

        $subtotal = round($items->sum('total'), 2);
        $taxAmount = round($subtotal * 0.18, 2);
        $shippingAmount = $subtotal > 1000 ? 0.0 : 50.0;
        $totalAmount = round($subtotal + $taxAmount + $shippingAmount, 2);

        return [
            'address' => $address,
            'items' => $items,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'shipping_amount' => $shippingAmount,
            'total_amount' => $totalAmount,
        ];
    }

    public function createOrder(User $user, array $payload, array $checkout): Order
    {
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
            'total_amount' => $checkout['total_amount'],
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
}
