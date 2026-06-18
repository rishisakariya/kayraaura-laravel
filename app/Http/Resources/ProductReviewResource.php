<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array for public product detail pages.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'review' => $this->review,
            'customer_name' => $this->whenLoaded('user', fn () => $this->user->name),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
