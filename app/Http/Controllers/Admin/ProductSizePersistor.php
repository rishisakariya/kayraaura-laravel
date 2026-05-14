<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductSize;
use Illuminate\Support\Facades\DB;

class ProductSizePersistor
{
    /**
     * Replace all sizes for a given product.
     *
     * Expected payload format:
     * sizes: [
     *   { size_text: string, quantity: int }, ...

     * ]
     */
    public function replaceForProduct(int $productId, array $sizes): void
    {
        DB::table('product_sizes')->where('product_id', $productId)->delete();

        foreach ($sizes as $size) {
            ProductSize::create([
                'product_id' => $productId,
                'size_text' => $size['size_text'],
                'quantity' => $size['quantity'],
                'price' => $size['price'],
            ]);

        }
    }
}

