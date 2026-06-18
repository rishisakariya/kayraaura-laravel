<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerReviewResource;
use App\Models\CustomerReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerReviewController extends Controller
{
    /**
     * Display customer review entries for the admin panel.
     */
    public function index(Request $request): JsonResponse
    {
        $reviews = CustomerReview::with(['product', 'user'])
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('review', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->input('product_id'), function ($query, $productId) {
                $query->where('product_id', $productId);
            })
            ->when($request->input('rating'), function ($query, $rating) {
                $query->where('rating', $rating);
            })
            ->when($request->has('on_web_show'), function ($query) use ($request) {
                $query->where('on_web_show', filter_var($request->input('on_web_show'), FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => CustomerReviewResource::collection($reviews),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    /**
     * Update review visibility on the product detail page.
     */
    public function update(Request $request, CustomerReview $customerReview): JsonResponse
    {
        $validated = $request->validate([
            'on_web_show' => ['required', 'boolean'],
        ]);

        $customerReview->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Review updated successfully',
            'data' => new CustomerReviewResource($customerReview->load(['product', 'user'])),
        ]);
    }
}
