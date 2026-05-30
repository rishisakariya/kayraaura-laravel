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
     * Upload a reusable image asset into the public storage disk.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            'folder' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'alt_text' => 'nullable|string|max:255',
        ]);

        $file = $validated['file'];
        $folder = $validated['folder'];
        $extension = strtolower($file->getClientOriginalExtension());
        $fileName = now()->timestamp . '_' . Str::random(16) . '.' . $extension;
        $filePath = $file->storeAs($folder, $fileName, 'public');

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'data' => [
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_url' => asset('storage/' . $filePath),
            ],
        ]);
    }

    /**
     * Delete an uploaded image from the public storage disk.
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
                'message' => 'Image not found',
            ], 404);
        }

        Storage::disk('public')->delete($filePath);

        return response()->json([
            'success' => true,
            'message' => 'Image deleted successfully',
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
