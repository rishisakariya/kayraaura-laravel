<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSizeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'size_id' => $this->size_id,
            'size_text' => $this->relationLoaded('size') && $this->size ? $this->size->name : $this->size_text,
            'size' => new SizeResource($this->whenLoaded('size')),
            'quantity' => (int) $this->quantity,
            'price' => $this->price !== null ? (float) $this->price : null,
            'discount_price' => $this->discount_price !== null ? (float) $this->discount_price : null,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}

