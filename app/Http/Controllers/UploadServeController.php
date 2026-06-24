<?php

namespace App\Http\Controllers;

use App\Support\PublicStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UploadServeController extends Controller
{
    /**
     * Serve an uploaded file when Apache cannot read it from public_html/uploads
     * (e.g. UPLOADS_DISK_ROOT is outside the web root or the symlink is missing).
     */
    public function show(string $path): BinaryFileResponse
    {
        if (str_contains($path, '..')) {
            abort(404);
        }

        $diskPath = PublicStorage::diskPath($path);

        if ($diskPath === null || !PublicStorage::exists($diskPath)) {
            abort(404);
        }

        return response()->file(
            PublicStorage::absolutePath($diskPath),
            ['Cache-Control' => 'public, max-age=31536000, immutable'],
        );
    }
}
