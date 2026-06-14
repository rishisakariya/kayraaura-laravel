<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SizeStoreRequest;
use App\Http\Resources\SizeResource;
use App\Models\Size;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SizeController extends Controller
{
    /**
     * Display a listing of sizes for the admin panel.
     */
    public function index(Request $request): JsonResponse
    {
        $sizes = Size::when($request->input('search'), function ($query, $search) {
            $query->where('name', 'like', "%{$search}%");
        })
            ->when($request->input('is_active') !== null, function ($query, $isActive) {
                $query->where('is_active', $isActive);
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => SizeResource::collection($sizes),
            'meta' => [
                'current_page' => $sizes->currentPage(),
                'last_page' => $sizes->lastPage(),
                'per_page' => $sizes->perPage(),
                'total' => $sizes->total(),
            ],
        ]);
    }

    /**
     * Store a newly created size or update by edit_value.
     */
    public function store(SizeStoreRequest $request): JsonResponse
    {
        if ((int) $request->input('edit_value', 0) > 0) {
            try {
                $size = Size::findOrFail($request->input('edit_value'));
            } catch (ModelNotFoundException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Size not found',
                ], 404);
            }

            return $this->updateSize($request, $size);
        }

        $size = Size::create([
            'name' => $request->input('name'),
            'sort_order' => $request->input('sort_order', 0),
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Size created successfully',
            'data' => new SizeResource($size),
        ], 201);
    }

    /**
     * Display the specified size.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $size = Size::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new SizeResource($size),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Size not found',
            ], 404);
        }
    }

    /**
     * Update the specified size.
     */
    public function update(SizeStoreRequest $request, string $id): JsonResponse
    {
        try {
            $size = Size::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Size not found',
            ], 404);
        }

        return $this->updateSize($request, $size);
    }

    /**
     * Remove the specified size.
     */
    public function destroy(string $id): JsonResponse
    {
        $size = Size::find($id);

        if (!$size) {
            return response()->json([
                'success' => false,
                'message' => 'Size not found',
            ], 404);
        }

        if ($size->productSizes()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete size used by products',
            ], 422);
        }

        $size->delete();

        return response()->json([
            'success' => true,
            'message' => 'Size deleted successfully',
        ]);
    }

    private function updateSize(SizeStoreRequest $request, Size $size): JsonResponse
    {
        $size->name = $request->input('name');
        $size->sort_order = $request->input('sort_order', $size->sort_order);
        $size->is_active = $request->input('is_active', $size->is_active);
        $size->save();

        return response()->json([
            'success' => true,
            'message' => 'Size updated successfully',
            'data' => new SizeResource($size),
        ]);
    }
}
