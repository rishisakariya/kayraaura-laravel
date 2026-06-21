<?php

namespace App\Services;

use App\Models\Order;
use App\Support\PublicStorage;
use Illuminate\Http\UploadedFile;

class OrderReturnService
{
    /**
     * @param  array<int, UploadedFile|null>  $images
     * @return array<int, string>
     */
    public function storeProductImages(Order $order, array $images): array
    {
        $stored = [];

        foreach (array_values($images) as $index => $image) {
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
     * @return array<string, mixed>
     */
    public function buildReturnRequestPayload(Order $order, array $validated, array $imageUrls): array
    {
        $payload = [
            'reason' => $validated['reason'],
            'product_images' => $imageUrls,
            'requested_at' => now()->toDateTimeString(),
        ];

        if ($order->payment_method === 'cod') {
            $payload['refund_details'] = [
                'full_name' => $validated['full_name'],
                'email' => $validated['email'],
                'mobile' => $validated['mobile'],
                'upi_id' => $validated['upi_id'],
            ];
        }

        return $payload;
    }
}
