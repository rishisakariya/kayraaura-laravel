<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductSize;
use App\Models\Size;
use Illuminate\Support\Facades\DB;

class ProductSizePersistor
{
    /**
     * Replace all sizes for a given product.
     *
     * Expected payload format:
     * sizes: [
     *   { size_id: int, quantity: int, price: numeric }, ...

     * ]
     */
    public function replaceForProduct(int $productId, array $sizes): void
    {
        DB::table('product_sizes')->where('product_id', $productId)->delete();

        $sizesById = Size::whereIn('id', collect($sizes)->pluck('size_id'))->get()->keyBy('id');

        foreach ($sizes as $size) {
            $masterSize = $sizesById->get($size['size_id']);

            ProductSize::create([
                'product_id' => $productId,
                'size_id' => $masterSize->id,
                'size_text' => $masterSize->name,
                'quantity' => $size['quantity'],
                'price' => $size['price'],
            ]);

        }
    }
}

