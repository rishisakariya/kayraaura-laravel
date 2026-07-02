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
            ->with(['user', 'shipment'])
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
                fn (array $entry) => ($entry['return_request_status'] ?? '') === $status
            );
        }

        if ($requestedFrom = $filters['requested_from'] ?? null) {
            $entries = $entries->filter(
                fn (array $entry) => ($entry['return_request']['requested_at'] ?? '') >= $requestedFrom
            );
        }

        if ($requestedTo = $filters['requested_to'] ?? null) {
            $entries = $entries->filter(
                fn (array $entry) => ($entry['return_request']['requested_at'] ?? '') <= $requestedTo . ' 23:59:59'
            );
        }

        if ($search = $filters['search'] ?? null) {
            $search = strtolower($search);
            $entries = $entries->filter(function (array $entry) use ($search) {
                $order = $entry['order'];
                $returnRequest = $entry['return_request'];
                $haystack = strtolower(implode(' ', array_filter([
                    $order->order_number ?? '',
                    $returnRequest['reason'] ?? '',
                    $order->user?->name ?? '',
                    $order->user?->email ?? '',
                    $order->user?->phone ?? '',
                    $returnRequest['refund_details']['upi_id'] ?? '',
                    $returnRequest['refund_details']['mobile'] ?? '',
                ])));

                return str_contains($haystack, $search);
            });
        }

        return $entries
            ->sortByDesc(fn (array $entry) => $entry['return_request']['requested_at'] ?? null)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $returnRequest
     * @return array<string, mixed>
     */
    private function buildEntry(Order $order, array $returnRequest): array
    {
        $canPayRefund = $this->orderReturnService->canPayReturnRefundForRequest($order, $returnRequest);

        return [
            'order' => $order,
            'return_request' => $returnRequest,
            'return_request_status' => $returnRequest['status'] ?? 'pending',
            'can_pay_refund' => $canPayRefund,
            'cod_refund_requires_upi_reference' => $order->payment_method === 'cod'
                && $canPayRefund
                && $this->orderReturnService->requiresUpiTransactionReferenceForCodRefund(),
        ];
    }
}
