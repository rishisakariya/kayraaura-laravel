<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutSummaryRequest;
use App\Http\Resources\AddressResource;
use App\Http\Resources\ProductResource;
use App\Services\CheckoutService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    public function summary(CheckoutSummaryRequest $request): JsonResponse
    {
        try {
            $checkout = $this->checkoutService->buildCheckout(Auth::user(), $request->validated());
            $items = $checkout['items']->map(function (array $item): array {
                $product = $item['product'] ?? null;
                unset($item['product']);

                return [
                    ...$item,
                    'product' => $product ? new ProductResource($product) : null,
                ];
            });

            return response()->json([
                'status' => true,
                'data' => [
                    'items' => $items->values(),
                    'subtotal' => $checkout['subtotal'],
                    'tax_amount' => $checkout['tax_amount'],
                    'shipping_amount' => $checkout['shipping_amount'],
                    'total_amount' => $checkout['total_amount'],
                    'payment_method' => $request->input('payment_method'),
                    'address' => new AddressResource($checkout['address']),
                ],
                'message' => 'Checkout summary generated successfully',
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
