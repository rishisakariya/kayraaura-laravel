<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutSummaryRequest;
use App\Http\Resources\AddressResource;
use App\Http\Resources\CheckoutItemResource;
use App\Services\CheckoutService;
use App\Services\ScratchCardService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly ScratchCardService $scratchCardService,
    ) {
    }

    public function summary(CheckoutSummaryRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();
            $checkout = $this->checkoutService->buildCheckout(Auth::user(), $payload);
            $checkout = $this->scratchCardService->applyCouponToCheckout(
                Auth::user(),
                $checkout,
                $payload['coupon_code'] ?? null
            );

            $items = CheckoutItemResource::collection($checkout['items']->values())
                ->resolve($request);

            return response()->json([
                'status' => true,
                'data' => [
                    'items' => $items,
                    'items_subtotal' => $checkout['items_subtotal'],
                    'buy_two_get_one_free_enabled' => $checkout['buy_two_get_one_free_enabled'],
                    'buy_two_get_one_discount_amount' => $checkout['buy_two_get_one_discount_amount'],
                    'first_order_discount_eligible' => $checkout['first_order_discount_eligible'],
                    'first_order_discount_amount' => $checkout['first_order_discount_amount'],
                    'online_payment_discount_percent' => $checkout['online_payment_discount_percent'] ?? null,
                    'online_payment_discount_amount' => $checkout['online_payment_discount_amount'] ?? 0,
                    'subtotal' => $checkout['subtotal'],
                    'tax_amount' => $checkout['tax_amount'],
                    'shipping_amount' => $checkout['shipping_amount'],
                    'cod_charge' => $checkout['cod_charge'],
                    'scratch_card_enabled' => $this->scratchCardService->isActive(),
                    'scratch_coupon_code' => $checkout['coupon_code'] ?? null,
                    'discount_percent' => $checkout['discount_percent'] ?? null,
                    'discount_amount' => $checkout['discount_amount'] ?? 0,
                    'total_amount' => $checkout['final_total_amount'] ?? $checkout['total_amount'],
                    'payment_method' => $request->input('payment_method'),
                    'address' => new AddressResource($checkout['address']),
                ],
                'message' => 'Checkout summary generated successfully',
            ]);
        } catch (DomainException $e) {
            if (!empty($request->input('coupon_code')) && !$this->scratchCardService->isActive()) {
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage(),
                    'error' => ['code' => 'SCRATCH_CARD_DISABLED'],
                ], 403);
            }

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
