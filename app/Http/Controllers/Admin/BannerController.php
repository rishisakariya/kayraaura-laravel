<?php

namespace App\Http\Controllers\Admin;

use App\Support\PublicStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerStoreRequest;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BannerController extends Controller
{
    /**
     * Display a listing of banners for the admin panel.
     */
    public function index(): JsonResponse
    {
        $banner = Banner::current();

        return response()->json([
            'success' => true,
            'data' => $banner ? new BannerResource($banner) : null,
        ]);
    }

    /**
     * Store a newly created banner in storage.
     */
    public function store(BannerStoreRequest $request): JsonResponse
    {
        $editValue = (int) $request->input('edit_value', 0);

        if ($editValue > 0) {
            try {
                $banner = Banner::findOrFail($editValue);
            } catch (ModelNotFoundException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Banner not found',
                ], 404);
            }

            return $this->updateBanner($request, $banner);
        }

        $existingBanner = Banner::current();

        if ($existingBanner) {
            return $this->updateBanner($request, $existingBanner);
        }

        $banner = Banner::create([
            'image' => $this->normalizeMediaArray($request->input('image', [])),
            'video' => $request->filled('video')
                ? PublicStorage::storePath($request->input('video'))
                : null,
            'banner_title' => $request->input('banner_title'),
            'banner_description' => $request->input('banner_description'),
            'video_title' => $request->input('video_title'),
            'video_description' => $request->input('video_description'),
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
        $this->deleteRemovedBannerMedia($banner->image ?? [], []);
        PublicStorage::delete($banner->video);
        $banner->delete();
        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Banner deleted successfully',
        ]);
    }

    private function updateBanner(BannerStoreRequest $request, Banner $banner): JsonResponse
    {
        DB::beginTransaction();

        if ($request->has('image')) {
            $newImages = $this->normalizeMediaArray($request->input('image', []));
            $this->deleteRemovedBannerMedia($banner->image ?? [], $newImages);
            $banner->image = $newImages;
        }

        if ($request->has('video')) {
            $newVideo = $request->filled('video')
                ? PublicStorage::storePath($request->input('video'))
                : null;

            if ($banner->video && (!$newVideo || PublicStorage::diskPath($banner->video) !== PublicStorage::diskPath($newVideo))) {
                PublicStorage::delete($banner->video);
            }

            $banner->video = $newVideo;
        }

        $banner->sort_order = $request->input('sort_order', $banner->sort_order);

        foreach (['banner_title', 'banner_description', 'video_title', 'video_description'] as $field) {
            if ($request->has($field)) {
                $banner->{$field} = $request->input($field);
            }
        }

        $banner->save();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Banner updated successfully',
            'data' => new BannerResource($banner),
        ]);
    }

    private function deleteRemovedBannerMedia(array $existingMedia, array $incomingMedia): void
    {
        $incomingPaths = array_map(
            fn (string $path) => PublicStorage::diskPath($path),
            $incomingMedia
        );

        foreach ($existingMedia as $mediaPath) {
            $normalizedPath = PublicStorage::diskPath($mediaPath);

            if (!in_array($normalizedPath, $incomingPaths, true)) {
                PublicStorage::delete($mediaPath);
            }
        }
    }

    private function normalizeMediaArray(array $media): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $path) => PublicStorage::storePath($path),
            $media
        ))));
    }
}
