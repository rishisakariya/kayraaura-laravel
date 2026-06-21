<?php

namespace App\Http\Controllers\Admin;

use App\Support\PublicStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerUpdateRequest;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BannerController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new BannerResource(Banner::current()),
        ]);
    }

    public function update(BannerUpdateRequest $request): JsonResponse
    {
        DB::beginTransaction();

        $banner = Banner::current();

        foreach (Banner::imageFields() as $field) {
            $newPath = PublicStorage::storePath($request->input($field));
            $oldPath = $banner->{$field};

            if ($oldPath && (!$newPath || PublicStorage::diskPath($oldPath) !== PublicStorage::diskPath($newPath))) {
                PublicStorage::delete($oldPath);
            }

            $banner->{$field} = $newPath;
        }

        $newVideo = $request->filled('video')
            ? PublicStorage::storePath($request->input('video'))
            : null;

        if ($banner->video && (!$newVideo || PublicStorage::diskPath($banner->video) !== PublicStorage::diskPath($newVideo))) {
            PublicStorage::delete($banner->video);
        }

        $banner->video = $newVideo;
        $banner->banner_title = $request->input('banner_title');
        $banner->banner_description = $request->input('banner_description');
        $banner->video_title = $request->input('video_title');
        $banner->video_description = $request->input('video_description');
        $banner->sort_order = $request->input('sort_order', $banner->sort_order);
        $banner->save();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Banner updated successfully',
            'data' => new BannerResource($banner->refresh()),
        ]);
    }
}
