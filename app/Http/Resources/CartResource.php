<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
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
            'user_id' => $this->user_id,
            'product_id' => $this->product_id,

            'product_size_id' => $this->product_size_id,
            'size_text' => $this->size_text,
            'size_price' => (float) $this->size_price,
            'quantity' => (int) $this->quantity,
            'subtotal' => round($this->subtotal, 2),
            'product_size' => new ProductSizeResource($this->whenLoaded('productSize')),
            'product' => $this->whenLoaded('product', function () {
                return [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'slug' => $this->product->slug,
                    'category' => $this->product->relationLoaded('category') && $this->product->category ? [
                        'id' => $this->product->category->id,
                        'name' => $this->product->category->name,
                        'slug' => $this->product->category->slug,
                    ] : null,
                    'images' => $this->product->relationLoaded('images')
                        ? ProductImageResource::collection($this->product->images)
                        : [],
                ];
            }),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
