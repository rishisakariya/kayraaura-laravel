<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryStoreRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = Category::with(['parent', 'children'])
            // ->withCount('products')
            ->when($request->input('search'), function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
            })
            ->when($request->input('is_active') !== null, function ($query, $isActive) {
                $query->where('is_active', $isActive);
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => CategoryResource::collection($categories),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CategoryStoreRequest $request): JsonResponse
    {
        if ((int)$request->input('edit_value') === 0) {
            // Create new category
            DB::beginTransaction();
            $category = new Category;
            $category->name = $request->input('name');
            $category->slug = $request->input('slug') ?: null;
            $category->description = $request->input('description');
            $category->image = $request->filled('image')
                ? $this->normalizePublicStorageUrl($request->input('image'))
                : null;
            $category->parent_id = $request->input('parent_id');
            $category->sort_order = $request->input('sort_order', 0);
            $category->is_active = $request->input('is_active', true);
            $category->save();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => new CategoryResource($category->load(['parent', 'children']))
            ]);
        }

        // Update existing category
        try {
    $category = Category::findOrFail($request->input('edit_value'));
} catch (ModelNotFoundException $e) {
    return response()->json([
        'success' => false,
        'message' => 'Category not found'
    ], 404);
}

        DB::beginTransaction();
        $category->name = $request->input('name');
        $category->slug = $request->input('slug') ?: null;
        $category->description = $request->input('description');

        if ($request->has('image')) {
            $newImage = $request->filled('image')
                ? $this->normalizePublicStorageUrl($request->input('image'))
                : null;

            if ($category->image && (!$newImage || $this->normalizePublicDiskPath($category->image) !== $this->normalizePublicDiskPath($newImage))) {
                $this->deleteCategoryImageFile($category->image);
            }

            $category->image = $newImage;
        }

        $category->parent_id = $request->input('parent_id');
        $category->sort_order = $request->input('sort_order', $category->sort_order);
        $category->is_active = $request->input('is_active', $category->is_active);
        $category->save();
        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => new CategoryResource($category->load(['parent', 'children']))
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $category = Category::with(['parent', 'children'])
                // ->withCount('products')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new CategoryResource($category)
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $category = Category::find($id);
        
        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }

        // Check if category has children
        if ($category->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with subcategories'
            ], 422);
        }

        // Check if category has products
        // if ($category->products()->count() > 0) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Cannot delete category with associated products'
        //     ], 422);
        // }

        DB::beginTransaction();
        if ($category->image) {
            $this->deleteCategoryImageFile($category->image);
        }

        $category->delete();
        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully'
        ]);
    }

    private function deleteCategoryImageFile(string $imagePath): void
    {
        $filePath = $this->normalizePublicDiskPath($imagePath);

        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
    }

    private function normalizePublicDiskPath(string $imagePath): string
    {
        $path = parse_url($imagePath, PHP_URL_PATH) ?: $imagePath;
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            return substr($path, strlen('storage/'));
        }

        return $path;
    }

    private function normalizePublicStorageUrl(string $imagePath): string
    {
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        return asset('storage/' . $this->normalizePublicDiskPath($imagePath));
    }
}
