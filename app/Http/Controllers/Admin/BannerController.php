<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerStoreRequest;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BannerController extends Controller
{
    /**
     * Display a listing of banners for the admin panel.
     */
    public function index(Request $request): JsonResponse
    {
        $banners = Banner::orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => BannerResource::collection($banners),
            'meta' => [
                'current_page' => $banners->currentPage(),
                'last_page' => $banners->lastPage(),
                'per_page' => $banners->perPage(),
                'total' => $banners->total(),
            ],
        ]);
    }

    /**
     * Store a newly created banner in storage.
     */
    public function store(BannerStoreRequest $request): JsonResponse
    {
        if ((int) $request->input('edit_value', 0) > 0) {
            try {
                $banner = Banner::findOrFail($request->input('edit_value'));
            } catch (ModelNotFoundException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Banner not found',
                ], 404);
            }

            return $this->updateBanner($request, $banner);
        }

        $banner = Banner::create([
            'image' => $this->normalizePublicStorageUrl($request->input('image')),
            'sort_order' => $request->input('sort_order', 0),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Banner created successfully',
            'data' => new BannerResource($banner),
        ], 201);
    }

    /**
     * Display the specified banner.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $banner = Banner::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new BannerResource($banner),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found',
            ], 404);
        }
    }

    /**
     * Remove the specified banner from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found',
            ], 404);
        }

        DB::beginTransaction();
        $this->deleteBannerImageFile($banner->image);
        $banner->delete();
        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Banner deleted successfully',
        ]);
    }

    private function deleteBannerImageFile(string $imagePath): void
    {
        $filePath = $this->normalizePublicDiskPath($imagePath);

        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
    }

    private function updateBanner(BannerStoreRequest $request, Banner $banner): JsonResponse
    {
        DB::beginTransaction();

        if ($request->has('image')) {
            $newImage = $this->normalizePublicStorageUrl($request->input('image'));

            if ($banner->image && $this->normalizePublicDiskPath($banner->image) !== $this->normalizePublicDiskPath($newImage)) {
                $this->deleteBannerImageFile($banner->image);
            }

            $banner->image = $newImage;
        }

        $banner->sort_order = $request->input('sort_order', $banner->sort_order);
        $banner->save();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Banner updated successfully',
            'data' => new BannerResource($banner),
        ]);
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

        return Storage::disk('public')->url($this->normalizePublicDiskPath($imagePath));
    }
}
