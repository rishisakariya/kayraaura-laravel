<?php

namespace App\Http\Controllers\Admin;

use App\Support\PublicStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryStoreRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'type' => ['nullable', 'in:main,sub'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $categories = Category::with(['parent', 'children'])
            // ->withCount('products')
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
            })
            ->when(array_key_exists('is_active', $validated), function ($query) use ($validated) {
                $isActive = $validated['is_active'];
                $query->where('is_active', $isActive);
            })
            ->when($validated['type'] ?? null, function ($query, $type) {
                $query->where('type', $type);
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15);

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
                ? PublicStorage::storePath($request->input('image'))
                : null;
            $category->type = $request->input('type');
            $category->parent_id = $request->input('type') === 'main'
                ? null
                : $request->input('parent_id');
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
                ? PublicStorage::storePath($request->input('image'))
                : null;

            if ($category->image && (!$newImage || PublicStorage::diskPath($category->image) !== PublicStorage::diskPath($newImage))) {
                PublicStorage::delete($category->image);
            }

            $category->image = $newImage;
        }

        // type is immutable after create; main keeps parent_id null, sub can update parent_id
        if ($category->type === 'sub') {
            $category->parent_id = $request->input('parent_id');
        } else {
            $category->parent_id = null;
        }

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

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        if ($category->type === 'main') {
            if ($category->children()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This main category cannot be deleted because it has subcategories. Please move or delete the subcategories first.',
                ], 422);
            }

            if ($category->products()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This main category cannot be deleted because it has products. Please move the products to another category first.',
                ], 422);
            }
        } elseif ($category->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This subcategory cannot be deleted because it has products. Please move the products to another category first.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($category) {
                if ($category->image) {
                    PublicStorage::delete($category->image);
                }

                $category->delete();
            });
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'This category cannot be deleted because it is linked to other records.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }
}
