<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class PublicStorage
{
    /**
     * Convert a stored value or URL to the relative path on the public disk.
     * e.g. "https://domain.com/storage/uploads/categories/a.jpg" → "categories/a.jpg"
     */
    public static function diskPath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = ltrim(str_replace('\\', '/', $path), '/');

        foreach (['storage/uploads/', 'storage/', 'uploads/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
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

        if (Storage::disk('public')->exists($diskPath)) {
            return true;
        }

        $legacyPath = storage_path('uploads/' . $diskPath);

        return is_file($legacyPath);
    }

    public static function delete(?string $path): bool
    {
        $diskPath = self::diskPath($path);

        if ($diskPath === null) {
            return false;
        }

        if (Storage::disk('public')->exists($diskPath)) {
            return Storage::disk('public')->delete($diskPath);
        }

        $legacyPath = storage_path('uploads/' . $diskPath);

        if (is_file($legacyPath)) {
            return unlink($legacyPath);
        }

        return false;
    }
}
