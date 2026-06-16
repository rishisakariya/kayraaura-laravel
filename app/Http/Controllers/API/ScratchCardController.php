<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ScratchCardService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ScratchCardController extends Controller
{
    public function __construct(private readonly ScratchCardService $scratchCardService)
    {
    }

    public function status(): JsonResponse
    {
        $settings = $this->scratchCardService->settings();

        return response()->json([
            'status' => true,
            'data' => [
                'is_active' => $settings->is_active,
                'min_discount_percent' => $settings->is_active ? $settings->min_discount_percent : null,
                'max_discount_percent' => $settings->is_active ? $settings->max_discount_percent : null,
            ],
            'message' => $settings->is_active
                ? 'Scratch card feature is active.'
                : 'Scratch card feature is currently disabled.',
        ]);
    }

    public function scratch(): JsonResponse
    {
        try {
            $this->scratchCardService->assertActive();

            $coupon = DB::transaction(function () {
                return $this->scratchCardService->scratch(Auth::user());
            });

            return response()->json([
                'status' => true,
                'data' => [
                    'coupon_code' => $coupon->code,
                    'discount_percent' => $coupon->discount_percent,
                ],
                'message' => 'Scratch card coupon generated successfully.',
            ], 201);
        } catch (DomainException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'error' => ['code' => 'SCRATCH_CARD_DISABLED'],
            ], 403);
        }
    }
}
