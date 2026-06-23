<?php

namespace App\Services;

use App\Models\Order;
use App\Support\PublicStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class OrderReturnService
{
    public function __construct(
        private readonly OrderRefundCalculator $refundCalculator,
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
