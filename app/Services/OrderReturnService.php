<?php

namespace App\Services;

use App\Models\Order;
use App\Support\PublicStorage;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * Process a return refund for online (Razorpay) or COD (UPI) orders.
     *
     * @return array<string, mixed>
     */
    public function processReturnRefund(
        Order $order,
        ?string $returnRequestId = null,
        ?string $upiTransactionReference = null,
    ): array {
        Log::channel('thirdparty')->info('Return refund flow: processing requested', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payment_method' => $order->payment_method,
            'return_request_id' => $returnRequestId,
        ]);

        return match ($order->payment_method) {
            'online' => $this->processOnlineReturnRefund($order, $returnRequestId),
            'cod' => $this->processCodReturnRefund($order, $returnRequestId, $upiTransactionReference),
            default => throw new DomainException('This order payment method is not supported for return refunds'),
        };
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
            $refundAmount = $this->resolveReturnRefundAmount($order, $awaitingRequest);

            if ($refundAmount <= 0) {
                throw new DomainException('Refund amount must be greater than zero');
            }

            $razorpayRefund = $this->checkoutService->refundRazorpayPayment(
                $order,
                'order_returned',
                $refundAmount
            );

            $this->finalizeReturnRefund(
                $order,
                $requestId,
                $refundAmount,
                function (array $request) use ($razorpayRefund) {
                    if ($razorpayRefundId = ($razorpayRefund['id'] ?? null)) {
                        $request['razorpay_refund_id'] = $razorpayRefundId;
                    }

                    return $request;
                }
            );

            Log::channel('thirdparty')->info('Return refund flow: online refund completed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'return_request_id' => $requestId,
                'refund_amount' => $refundAmount,
                'razorpay_refund_id' => $razorpayRefund['id'] ?? null,
            ]);

            return [
                'refund_amount' => $refundAmount,
                'return_request_id' => $requestId,
                'payment_method' => 'online',
                'razorpay_refund' => $razorpayRefund,
            ];
        });
    }

    /**
     * Process a COD return refund to the customer's UPI ID.
     *
     * @return array{refund_amount: float, return_request_id: string|null, upi_id: string, payout: array<string, mixed>|null}
     */
    public function processCodReturnRefund(
        Order $order,
        ?string $returnRequestId = null,
        ?string $upiTransactionReference = null,
    ): array {
        if ($order->payment_method !== 'cod') {
            throw new DomainException('Only COD orders can be refunded through UPI');
        }

        return DB::transaction(function () use ($order, $returnRequestId, $upiTransactionReference) {
            $order = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $awaitingRequest = $this->findAwaitingRefundRequest($order, $returnRequestId);

            if (!$awaitingRequest) {
                throw new DomainException('No return refund is available to process');
            }

            $refundDetails = $awaitingRequest['refund_details'] ?? [];
            $upiId = trim((string) ($refundDetails['upi_id'] ?? ''));

            if ($upiId === '') {
                throw new DomainException('UPI ID is missing for this COD return request');
            }

            $requestId = $awaitingRequest['id'] ?? null;
            $refundAmount = $this->resolveReturnRefundAmount($order, $awaitingRequest);

            if ($refundAmount <= 0) {
                throw new DomainException('Refund amount must be greater than zero');
            }

            $payout = null;
            $refundMethod = 'manual_upi';

            if (config('services.razorpay.x_account_number')) {
                $payout = $this->checkoutService->payoutToUpi(
                    $order,
                    $upiId,
                    (string) ($refundDetails['full_name'] ?? 'Customer'),
                    (string) ($refundDetails['email'] ?? 'customer@example.com'),
                    (string) ($refundDetails['mobile'] ?? '9999999999'),
                    $refundAmount,
                    'return_' . ($requestId ?? $order->id),
                    ['reason' => 'order_returned']
                );
                $refundMethod = 'razorpay_payout';
            } elseif (!$upiTransactionReference) {
                throw new DomainException(
                    'Send the UPI payment first, then provide upi_transaction_reference to confirm the refund'
                );
            }

            $this->finalizeReturnRefund(
                $order,
                $requestId,
                $refundAmount,
                function (array $request) use ($payout, $upiTransactionReference, $refundMethod, $upiId) {
                    $request['refund_method'] = $refundMethod;
                    $request['upi_id'] = $upiId;

                    if ($payoutId = ($payout['id'] ?? null)) {
                        $request['razorpay_payout_id'] = $payoutId;
                    }

                    if ($upiTransactionReference) {
                        $request['upi_transaction_reference'] = $upiTransactionReference;
                    }

                    return $request;
                }
            );

            Log::channel('thirdparty')->info('Return refund flow: COD refund completed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'return_request_id' => $requestId,
                'refund_amount' => $refundAmount,
                'refund_method' => $refundMethod,
                'payout_id' => $payout['id'] ?? null,
            ]);

            return [
                'refund_amount' => $refundAmount,
                'return_request_id' => $requestId,
                'payment_method' => 'cod',
                'upi_id' => $upiId,
                'payout' => $payout,
            ];
        });
    }

    public function canPayReturnRefund(Order $order): bool
    {
        $request = $this->latestPayableReturnRequest($order);

        return $request && $this->canPayReturnRefundForRequest($order, $request);
    }

    /**
     * @param  array<string, mixed>  $returnRequest
     */
    public function canPayReturnRefundForRequest(Order $order, array $returnRequest): bool
    {
        if (!$this->returnRequestIsPayable($returnRequest, $order)) {
            return false;
        }

        if ($order->payment_method === 'online') {
            return $order->payment_status === 'paid';
        }

        if ($order->payment_method === 'cod') {
            return !empty($returnRequest['refund_details']['upi_id']);
        }

        return false;
    }

    public function requiresUpiTransactionReferenceForCodRefund(): bool
    {
        return !config('services.razorpay.x_account_number');
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $requestMutator
     */
    private function finalizeReturnRefund(
        Order $order,
        ?string $requestId,
        float $refundAmount,
        callable $requestMutator,
    ): void {
        $returnRequest = $order->return_request ?? ['requests' => [], 'total_refunded_amount' => 0.0];

        if (!isset($returnRequest['requests']) || !is_array($returnRequest['requests'])) {
            $returnRequest['requests'] = [];
        }

        $refundedAt = now()->toDateTimeString();

        $returnRequest['requests'] = collect($returnRequest['requests'])
            ->map(function (array $request) use ($requestId, $refundedAt, $requestMutator) {
                if (($request['id'] ?? null) !== $requestId) {
                    return $request;
                }

                $request['status'] = 'completed';
                $request['refunded_at'] = $refundedAt;
                $request['completed_at'] = $request['completed_at'] ?? $refundedAt;

                return $requestMutator($request);
            })
            ->all();

        $returnRequest['total_refunded_amount'] = round(
            (float) ($returnRequest['total_refunded_amount'] ?? 0) + $refundAmount,
            2
        );

        $order->return_request = $returnRequest;

        if (in_array($order->payment_method, ['online', 'cod'], true)
            && $returnRequest['total_refunded_amount'] >= (float) $order->total_amount) {
            $order->payment_status = 'refunded';
        }

        $order->save();

        Log::channel('thirdparty')->info('Return refund flow: return request finalized', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'return_request_id' => $requestId,
            'refund_amount' => $refundAmount,
            'payment_status' => $order->payment_status,
            'total_refunded_amount' => $returnRequest['total_refunded_amount'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAwaitingRefundRequest(Order $order, ?string $returnRequestId): ?array
    {
        $requests = $this->refundCalculator->normalizeReturnRequests($order)
            ->filter(fn (array $request) => $this->returnRequestIsPayable($request, $order));

        if ($returnRequestId) {
            return $requests->first(fn (array $request) => ($request['id'] ?? null) === $returnRequestId);
        }

        return $requests->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestPayableReturnRequest(Order $order): ?array
    {
        return $this->refundCalculator->normalizeReturnRequests($order)
            ->first(fn (array $request) => $this->returnRequestIsPayable($request, $order));
    }

    /**
     * @param  array<string, mixed>  $returnRequest
     */
    private function returnRequestIsPayable(array $returnRequest, Order $order): bool
    {
        $status = $returnRequest['status'] ?? '';

        if ($status === 'awaiting_refund') {
            return true;
        }

        return $status === 'completed'
            && empty($returnRequest['refunded_at'])
            && $this->resolveReturnRefundAmount($order, $returnRequest) > 0;
    }

    /**
     * @param  array<string, mixed>  $returnRequest
     */
    private function resolveReturnRefundAmount(Order $order, array $returnRequest): float
    {
        $stored = round((float) ($returnRequest['refund_amount'] ?? 0), 2);

        if ($stored > 0) {
            return $stored;
        }

        $alreadyRefunded = round((float) (($order->return_request ?? [])['total_refunded_amount'] ?? 0), 2);

        return round(max((float) $order->total_amount - $alreadyRefunded, 0), 2);
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
