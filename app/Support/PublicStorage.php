<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PublicStorage
{
    public const DISK = 'uploads';

    /**
     * Store an uploaded file under public uploads, e.g. products/, categories/.
     */
    public static function storeUploadedFile(UploadedFile $file, string $folder, ?string $fileName = null): string
    {
        $folder = self::normalizeFolder($folder);
        self::ensureDirectory($folder);

        if ($fileName !== null) {
            return $file->storeAs($folder, $fileName, self::DISK);
        }

        return $file->store($folder, self::DISK);
    }

    public static function ensureDirectory(string $folder): void
    {
        $folder = self::normalizeFolder($folder);

        if ($folder === '') {
            return;
        }

        self::disk()->makeDirectory($folder);
    }

    public static function normalizeFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');

        return match ($folder) {
            'web-settings' => 'settings',
            default => $folder,
        };
    }

    /**
     * Convert a stored value or URL to the relative path on the uploads disk.
     * e.g. "https://domain.com/uploads/products/a.jpg" → "products/a.jpg"
     */
    public static function diskPath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = ltrim(str_replace('\\', '/', $path), '/');

        foreach ([
            'public/uploads/',
            'uploads/',
            'public/storage/uploads/',
            'public/storage/',
            'storage/uploads/',
            'storage/',
        ] as $prefix) {
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

        return self::disk()->url($diskPath);
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

        return self::disk()->exists($diskPath);
    }

    public static function delete(?string $path): bool
    {
        $diskPath = self::diskPath($path);

        if ($diskPath === null) {
            return false;
        }

        return self::disk()->delete($diskPath);
    }

    public static function put(string $path, mixed $contents): bool
    {
        $diskPath = self::diskPath($path) ?? self::normalizeFolder($path);
        self::ensureDirectory(dirname($diskPath) === '.' ? '' : dirname($diskPath));

        return self::disk()->put($diskPath, $contents);
    }

    public static function get(string $path): ?string
    {
        $diskPath = self::diskPath($path);

        if ($diskPath === null || !self::disk()->exists($diskPath)) {
            return null;
        }

        return self::disk()->get($diskPath);
    }

    public static function absolutePath(string $path): string
    {
        $diskPath = self::diskPath($path) ?? self::normalizeFolder($path);

        return self::disk()->path($diskPath);
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
