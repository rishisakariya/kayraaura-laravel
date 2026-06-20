<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = (array) $this->resource;
        $product = $item['product'] ?? null;
        unset($item['product']);

        return [
            ...$item,
            'product' => $this->formatProduct($product, $request),
        ];
    }

    private function formatProduct(?Product $product, Request $request): ?array
    {
        if (!$product) {
            return null;
        }

        if (!$product->relationLoaded('images')) {
            $product->load('images');
        }

        $images = ProductImageResource::collection($product->images)->resolve($request);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'category' => $product->relationLoaded('category') && $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'image' => $images,
            'images' => $images,
        ];
    }
}
