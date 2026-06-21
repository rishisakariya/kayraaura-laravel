<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\PublicStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MediaController extends Controller
{
    private const UPLOAD_FOLDERS = [
        'products',
        'categories',
        'banners',
        'web-settings',
        'media',
    ];

    /**
     * Upload a reusable media asset into the public storage disk.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,mov,avi,webm|max:51200',
            'folder' => ['required', 'string', Rule::in(self::UPLOAD_FOLDERS)],
            'alt_text' => 'nullable|string|max:255',
        ]);

        $file = $validated['file'];
        $folder = $validated['folder'];
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();
        $mediaType = str_starts_with((string) $mimeType, 'video/') ? 'video' : 'image';
        $fileName = now()->timestamp . '_' . Str::random(16) . '.' . $extension;
        $filePath = PublicStorage::storeUploadedFile($file, $folder, $fileName);
        $fileUrl = PublicStorage::url($filePath);

        return response()->json([
            'success' => true,
            'message' => 'Media uploaded successfully',
            'data' => [
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_url' => $fileUrl,
                'media_type' => $mediaType,
                'mime_type' => $mimeType,
            ],
        ]);
    }

    /**
     * Delete an uploaded media file from the public storage disk.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_path' => ['required', 'string', 'max:2048', 'not_regex:/\.\./'],
        ]);

        $filePath = PublicStorage::diskPath($validated['file_path']);

        if ($filePath === null || !PublicStorage::exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Media not found',
            ], 404);
        }

        PublicStorage::delete($filePath);

        return response()->json([
            'success' => true,
            'message' => 'Media deleted successfully',
        ]);
    }
}
