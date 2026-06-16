<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ScratchCardCouponResource;
use App\Models\ScratchCardCoupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScratchCardCouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_redeemed' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', Rule::in(['created_at', 'redeemed_at', 'discount_percent', 'discount_amount'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDirection = $validated['sort_direction'] ?? 'desc';

        $coupons = ScratchCardCoupon::query()
            ->with(['user', 'order'])
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        })
                        ->orWhereHas('order', fn ($query) => $query->where('order_number', 'like', "%{$search}%"));
                });
            })
            ->when($request->has('is_redeemed'), fn ($query) => $query->where('is_redeemed', $request->boolean('is_redeemed')))
            ->when($request->input('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->input('created_from'), fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($request->input('created_to'), fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->orderBy($sortBy, $sortDirection)
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => ScratchCardCouponResource::collection($coupons),
            'meta' => [
                'current_page' => $coupons->currentPage(),
                'last_page' => $coupons->lastPage(),
                'per_page' => $coupons->perPage(),
                'total' => $coupons->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $coupon = ScratchCardCoupon::with(['user', 'order'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new ScratchCardCouponResource($coupon),
        ]);
    }
}
