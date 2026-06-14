<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    /**
     * Upload a reusable media asset into the public storage disk.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,mov,avi,webm|max:51200',
            'folder' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'alt_text' => 'nullable|string|max:255',
        ]);

        $file = $validated['file'];
        $folder = $validated['folder'];
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();
        $mediaType = str_starts_with((string) $mimeType, 'video/') ? 'video' : 'image';
        $fileName = now()->timestamp . '_' . Str::random(16) . '.' . $extension;
        $filePath = $file->storeAs($folder, $fileName, 'public');
        $fileUrl = Storage::disk('public')->url($filePath);

        return response()->json([
            'success' => true,
            'message' => 'Media uploaded successfully',
            'data' => [
                'file_name' => $fileName,
                'file_path' => $fileUrl,
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

        $filePath = $this->normalizePublicDiskPath($validated['file_path']);

        if (!Storage::disk('public')->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Media not found',
            ], 404);
        }

        Storage::disk('public')->delete($filePath);

        return response()->json([
            'success' => true,
            'message' => 'Media deleted successfully',
        ]);
    }

    private function normalizePublicDiskPath(string $filePath): string
    {
        $path = parse_url($filePath, PHP_URL_PATH) ?: $filePath;
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            return substr($path, strlen('storage/'));
        }

        return $path;
    }
}
