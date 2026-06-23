<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

class OrderReturnListingService
{
    public function __construct(
        private readonly OrderRefundCalculator $refundCalculator,
        private readonly OrderReturnService $orderReturnService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(string $paymentMethod, array $filters = []): LengthAwarePaginator
    {
        $orders = Order::query()
            ->with(['user'])
            ->where('payment_method', $paymentMethod)
            ->whereNotNull('return_request')
            ->when(
                $paymentMethod === 'online',
                fn ($query) => $query->where('payment_status', '!=', 'pending')
            )
            ->orderByDesc('updated_at')
            ->get();

        $entries = $this->flattenReturnEntries($orders, $filters);

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = max((int) ($filters['per_page'] ?? 15), 1);
        $total = $entries->count();
        $items = $entries->slice(($page - 1) * $perPage, $perPage)->values();

        return new Paginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function flattenReturnEntries(Collection $orders, array $filters): Collection
    {
        $entries = $orders->flatMap(function (Order $order) {
            return $this->refundCalculator->normalizeReturnRequests($order)
                ->map(fn (array $returnRequest) => $this->buildEntry($order, $returnRequest));
        });

        if ($status = $filters['status'] ?? null) {
            $entries = $entries->filter(
                fn (array $entry) => ($entry['status'] ?? '') === $status
            );
        }

        if ($requestedFrom = $filters['requested_from'] ?? null) {
            $entries = $entries->filter(
                fn (array $entry) => ($entry['requested_at'] ?? '') >= $requestedFrom
            );
        }

        if ($requestedTo = $filters['requested_to'] ?? null) {
            $entries = $entries->filter(
                fn (array $entry) => ($entry['requested_at'] ?? '') <= $requestedTo . ' 23:59:59'
            );
        }

        if ($search = $filters['search'] ?? null) {
            $search = strtolower($search);
            $entries = $entries->filter(function (array $entry) use ($search) {
                $haystack = strtolower(implode(' ', array_filter([
                    $entry['order_number'] ?? '',
                    $entry['reason'] ?? '',
                    $entry['customer']['name'] ?? '',
                    $entry['customer']['email'] ?? '',
                    $entry['customer']['phone'] ?? '',
                    $entry['refund_details']['upi_id'] ?? '',
                    $entry['refund_details']['mobile'] ?? '',
                ])));

                return str_contains($haystack, $search);
            });
        }

        return $entries
            ->sortByDesc('requested_at')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $returnRequest
     * @return array<string, mixed>
     */
    private function buildEntry(Order $order, array $returnRequest): array
    {
        $refundDetails = $returnRequest['refund_details'] ?? null;
        $canPayRefund = $this->orderReturnService->canPayReturnRefundForRequest($order, $returnRequest);

        return [
            'return_request_id' => $returnRequest['id'] ?? null,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payment_method' => $order->payment_method,
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
            'order_total_amount' => (float) $order->total_amount,
            'customer' => [
                'id' => $order->user?->id,
                'name' => $order->user?->name,
                'email' => $order->user?->email,
                'phone' => $order->user?->phone,
            ],
            'reason' => $returnRequest['reason'] ?? null,
            'items' => $returnRequest['items'] ?? [],
            'refund_amount' => isset($returnRequest['refund_amount'])
                ? (float) $returnRequest['refund_amount']
                : null,
            'is_partial' => (bool) ($returnRequest['is_partial'] ?? false),
            'status' => $returnRequest['status'] ?? 'pending',
            'product_images' => $returnRequest['product_images'] ?? [],
            'refund_details' => is_array($refundDetails) ? $refundDetails : null,
            'requested_at' => $returnRequest['requested_at'] ?? null,
            'received_at' => $returnRequest['received_at'] ?? null,
            'refunded_at' => $returnRequest['refunded_at'] ?? null,
            'completed_at' => $returnRequest['completed_at'] ?? null,
            'refund_method' => $returnRequest['refund_method'] ?? null,
            'razorpay_refund_id' => $returnRequest['razorpay_refund_id'] ?? null,
            'razorpay_payout_id' => $returnRequest['razorpay_payout_id'] ?? null,
            'upi_transaction_reference' => $returnRequest['upi_transaction_reference'] ?? null,
            'can_pay_refund' => $canPayRefund,
            'cod_refund_requires_upi_reference' => $order->payment_method === 'cod'
                && $canPayRefund
                && $this->orderReturnService->requiresUpiTransactionReferenceForCodRefund(),
        ];
    }
}
