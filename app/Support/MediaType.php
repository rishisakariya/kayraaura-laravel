<?php

namespace App\Support;

class MediaType
{
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'webm'];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public static function fromPath(?string $path): string
    {
        return self::isVideoPath($path) ? 'video' : 'image';
    }

    public static function isVideoPath(?string $path): bool
    {
        if ($path === null || trim($path) === '') {
            return false;
        }

        return in_array(self::extensionFromPath($path), self::VIDEO_EXTENSIONS, true);
    }

    public static function isAllowedPath(?string $path): bool
    {
        if ($path === null || trim($path) === '') {
            return false;
        }

        return in_array(
            self::extensionFromPath($path),
            array_merge(self::IMAGE_EXTENSIONS, self::VIDEO_EXTENSIONS),
            true,
        );
    }

    private static function extensionFromPath(string $path): string
    {
        return strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
    }
}
