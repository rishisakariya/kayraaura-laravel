<?php

namespace App\Services;

use App\Models\Order;
use App\Support\PublicStorage;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderReturnService
{
    public function __construct(
        private readonly OrderRefundCalculator $refundCalculator,
        private readonly CheckoutService $checkoutService,
    ) {
    }

    /**
     * @param  array<int, UploadedFile|null>  $images
     * @return array<int, string>
     */
    public function storeProductImages(Order $order, array $images): array
    {
        $stored = [];

        foreach (array_values($images) as $image) {
            if (!$image instanceof UploadedFile) {
                continue;
            }

            $path = PublicStorage::storeUploadedFile($image, "order-returns/{$order->id}");
            $stored[] = PublicStorage::url($path);
        }

        return $stored;
    }

    /**
     * @param  array<int, string>  $imageUrls
     */
    public function deleteProductImages(array $imageUrls): void
    {
        foreach ($imageUrls as $url) {
            PublicStorage::delete($url);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{items: array<int, array<string, mixed>>, refund_amount: float, is_partial: bool}  $refundCalculation
     * @return array<string, mixed>
     */
    public function buildReturnRequestPayload(
        Order $order,
        array $validated,
        array $imageUrls,
        array $refundCalculation,
    ): array {
        $request = [
            'id' => (string) Str::uuid(),
            'status' => 'pending',
            'reason' => $validated['reason'],
            'items' => $refundCalculation['items'],
            'refund_amount' => $refundCalculation['refund_amount'],
            'is_partial' => $refundCalculation['is_partial'],
            'product_images' => $imageUrls,
            'requested_at' => now()->toDateTimeString(),
        ];

        if ($order->payment_method === 'cod') {
            $request['refund_details'] = [
                'full_name' => $validated['full_name'],
                'email' => $validated['email'],
                'mobile' => $validated['mobile'],
                'upi_id' => $validated['upi_id'],
            ];
        }

        $existing = $this->normalizeStoredReturnRequest($order);
        $existing['requests'][] = $request;

        return $existing;
    }

    /**
     * @param  array<int, array{order_item_id: int, quantity: int}>  $items
     * @return array{items: array<int, array<string, mixed>>, refund_amount: float, is_partial: bool}
     */
    public function calculateRefund(Order $order, array $items): array
    {
        $quantities = $this->refundCalculator->mapReturnItemsToQuantities($items);

        return $this->refundCalculator->calculateReturnRefund($order, $quantities);
    }

    public function buildOrderReturnSummary(Order $order): array
    {
        return $this->refundCalculator->buildOrderReturnSummary($order);
    }

    /**
     * Process a manual Razorpay refund for a return received at the warehouse.
     *
     * @return array{refund_amount: float, return_request_id: string|null, razorpay_refund: array<string, mixed>}
     */
    public function processOnlineReturnRefund(Order $order, ?string $returnRequestId = null): array
    {
        if ($order->payment_method !== 'online') {
            throw new DomainException('Only online orders can be refunded through Razorpay');
        }

        if ($order->payment_status !== 'paid') {
            throw new DomainException('This order payment is not eligible for a return refund');
        }

        return DB::transaction(function () use ($order, $returnRequestId) {
            $order = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $awaitingRequest = $this->findAwaitingRefundRequest($order, $returnRequestId);

            if (!$awaitingRequest) {
                throw new DomainException('No return refund is available to process');
            }

            $requestId = $awaitingRequest['id'] ?? null;
            $refundAmount = round((float) ($awaitingRequest['refund_amount'] ?? 0), 2);

            if ($refundAmount <= 0) {
                throw new DomainException('Refund amount must be greater than zero');
            }

            $razorpayRefund = $this->checkoutService->refundRazorpayPayment(
                $order,
                'order_returned',
                $refundAmount
            );

            $returnRequest = $order->return_request ?? ['requests' => [], 'total_refunded_amount' => 0.0];

            if (!isset($returnRequest['requests']) || !is_array($returnRequest['requests'])) {
                $returnRequest['requests'] = [];
            }

            $refundedAt = now()->toDateTimeString();
            $razorpayRefundId = $razorpayRefund['id'] ?? null;

            $returnRequest['requests'] = collect($returnRequest['requests'])
                ->map(function (array $request) use ($requestId, $refundedAt, $razorpayRefundId) {
                    if (($request['id'] ?? null) !== $requestId) {
                        return $request;
                    }

                    $request['status'] = 'completed';
                    $request['refunded_at'] = $refundedAt;
                    $request['completed_at'] = $request['completed_at'] ?? $refundedAt;

                    if ($razorpayRefundId) {
                        $request['razorpay_refund_id'] = $razorpayRefundId;
                    }

                    return $request;
                })
                ->all();

            $returnRequest['total_refunded_amount'] = round(
                (float) ($returnRequest['total_refunded_amount'] ?? 0) + $refundAmount,
                2
            );

            $order->return_request = $returnRequest;

            if ($returnRequest['total_refunded_amount'] >= (float) $order->total_amount) {
                $order->payment_status = 'refunded';
            }

            $order->save();

            return [
                'refund_amount' => $refundAmount,
                'return_request_id' => $requestId,
                'razorpay_refund' => $razorpayRefund,
            ];
        });
    }

    public function canPayReturnRefund(Order $order): bool
    {
        return $order->payment_method === 'online'
            && $order->payment_status === 'paid'
            && $this->refundCalculator->hasAwaitingRefundReturnRequest($order);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAwaitingRefundRequest(Order $order, ?string $returnRequestId): ?array
    {
        $requests = $this->refundCalculator->normalizeReturnRequests($order)
            ->filter(fn (array $request) => ($request['status'] ?? '') === 'awaiting_refund');

        if ($returnRequestId) {
            return $requests->first(fn (array $request) => ($request['id'] ?? null) === $returnRequestId);
        }

        return $requests->first();
    }

    /**
     * @return array{requests: array<int, array<string, mixed>>, total_refunded_amount: float}
     */
    private function normalizeStoredReturnRequest(Order $order): array
    {
        $data = $order->return_request ?? [];

        if (isset($data['requests']) && is_array($data['requests'])) {
            return [
                'requests' => $data['requests'],
                'total_refunded_amount' => (float) ($data['total_refunded_amount'] ?? 0),
            ];
        }

        if ($data !== [] && isset($data['reason'])) {
            return [
                'requests' => [
                    array_merge($data, [
                        'status' => $order->status === 'return_requested' ? 'pending' : 'completed',
                    ]),
                ],
                'total_refunded_amount' => $order->status === 'returned'
                    ? (float) ($data['refund_amount'] ?? $order->total_amount)
                    : 0.0,
            ];
        }

        return [
            'requests' => [],
            'total_refunded_amount' => 0.0,
        ];
    }
}
