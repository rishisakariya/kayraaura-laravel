<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PublicStorage
{
    /**
     * Store an uploaded file on the public disk, e.g. products/, categories/.
     */
    public static function storeUploadedFile(UploadedFile $file, string $folder, ?string $fileName = null): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');

        if ($fileName !== null) {
            return $file->storeAs($folder, $fileName, 'public');
        }

        return $file->store($folder, 'public');
    }

    /**
     * Convert a stored value or URL to the relative path on the public disk.
     * e.g. "https://domain.com/storage/products/a.jpg" → "products/a.jpg"
     */
    public static function diskPath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_starts_with($path, 'storage/')) {
            return substr($path, strlen('storage/'));
        }

        return $path;
    }

    /**
     * Build a public URL for a relative or absolute stored media path.
     */
    public static function url(?string $path): ?string
    {
        $diskPath = self::diskPath($path);

        if ($diskPath === null) {
            return null;
        }

        return Storage::disk('public')->url($diskPath);
    }

    /**
     * Normalize incoming media input before saving to the database.
     */
    public static function storePath(?string $path): ?string
    {
        return self::diskPath($path);
    }

    public static function exists(?string $path): bool
    {
        $diskPath = self::diskPath($path);

        if ($diskPath === null) {
            return false;
        }

        return Storage::disk('public')->exists($diskPath);
    }

    public static function delete(?string $path): bool
    {
        $diskPath = self::diskPath($path);

        if ($diskPath === null) {
            return false;
        }

        return Storage::disk('public')->delete($diskPath);
    }
}
