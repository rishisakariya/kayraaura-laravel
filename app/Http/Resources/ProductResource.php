<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'category_id' => $this->category_id,
            'is_active' => $this->is_active,
            'track_stock' => $this->track_stock,
            'discount_percentage' => $this->discount_percentage,
            'brand' => $this->brand,
            'base_material' => $this->base_material,
            'plating' => $this->plating,
            'gemstone' => $this->gemstone,
            'design' => $this->design,
            'occasion' => $this->occasion,
            'ideal_for' => $this->ideal_for,
            'package_contents' => $this->package_contents,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'image' => ProductImageResource::collection($this->whenLoaded('images')),
            // 'primary_image' => ProductImageResource::collection($this->whenLoaded('primaryImage')),
            'sizes' => ProductSizeResource::collection($this->whenLoaded('sizes')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
