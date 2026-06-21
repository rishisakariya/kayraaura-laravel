<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PublicStorage
{
    /**
     * Build the storage directory for a media type (products, categories, banners, …).
     * Uses "uploads/{folder}" unless the public disk root already ends with /uploads.
     */
    public static function uploadFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $root = str_replace('\\', '/', (string) config('filesystems.disks.public.root'));

        if (preg_match('#/uploads/?$#', $root)) {
            return $folder;
        }

        return 'uploads/' . $folder;
    }

    /**
     * Store an uploaded file on the public disk under uploads/{folder}/….
     */
    public static function storeUploadedFile(UploadedFile $file, string $folder, ?string $fileName = null): string
    {
        $directory = self::uploadFolder($folder);

        if ($fileName !== null) {
            return $file->storeAs($directory, $fileName, 'public');
        }

        return $file->store($directory, 'public');
    }

    /**
     * Convert a stored value or URL to the relative path on the public disk.
     * e.g. "https://domain.com/storage/uploads/categories/a.jpg" → "uploads/categories/a.jpg"
     */
    public static function diskPath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $root = str_replace('\\', '/', (string) config('filesystems.disks.public.root'));

        if (preg_match('#/uploads/?$#', $root) && str_starts_with($path, 'uploads/')) {
            $path = substr($path, strlen('uploads/'));
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

        $legacyPath = self::legacyAbsolutePath($diskPath);

        return $legacyPath !== null && is_file($legacyPath);
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

        $legacyPath = self::legacyAbsolutePath($diskPath);

        if ($legacyPath !== null && is_file($legacyPath)) {
            return unlink($legacyPath);
        }

        return false;
    }

    private static function legacyAbsolutePath(string $diskPath): ?string
    {
        $relative = str_starts_with($diskPath, 'uploads/')
            ? substr($diskPath, strlen('uploads/'))
            : $diskPath;

        if ($relative === '') {
            return null;
        }

        return storage_path('uploads/' . $relative);
    }
}
