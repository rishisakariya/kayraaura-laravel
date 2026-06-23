<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_size_id' => $this->product_size_id,
            'product_name' => $this->product_name,
            'product_slug' => $this->product_slug,
            'size_text' => $this->size_text,
            'size_price' => (float) $this->size_price,
            'quantity' => $this->quantity,
            'returned_quantity' => (int) $this->returned_quantity,
            'returnable_quantity' => $this->returnableQuantity(),
            'price' => (float) $this->price,
            'total' => (float) $this->total,
            'product' => $this->whenLoaded('product', function () {
                return [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'slug' => $this->product->slug,
                    'images' => $this->product->relationLoaded('images')
                        ? ProductImageResource::collection($this->product->images)
                        : [],
                ];
            }),
            'product_size' => new ProductSizeResource($this->whenLoaded('productSize')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
