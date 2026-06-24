<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class EnsureUploadsLinkCommand extends Command
{
    protected $signature = 'uploads:ensure-link
                            {--migrate : Move existing files from the web uploads folder into persistent storage}';

    protected $description = 'Prepare persistent upload storage and symlink when the server allows it (Hostinger after deploy).';

    public function handle(): int
    {
        $storageRoot = $this->normalizePath((string) config('filesystems.disks.uploads.root'));
        $linkPath = $this->normalizePath((string) config('filesystems.disks.uploads.link', base_path('uploads')));

        if ($storageRoot === '' || $linkPath === '') {
            $this->error('Uploads paths are not configured.');

            return self::FAILURE;
        }

        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0755, true) && !is_dir($storageRoot)) {
            $this->error("Could not create uploads storage directory: {$storageRoot}");

            return self::FAILURE;
        }

        $this->writeHtaccess($storageRoot);

        $storageReal = realpath($storageRoot);

        if ($storageReal === false) {
            $this->error("Uploads storage directory is not readable: {$storageRoot}");

            return self::FAILURE;
        }

        if ($this->normalizePath($linkPath) === $this->normalizePath($storageReal)) {
            $this->info("Uploads directory ready at {$storageReal}");

            return self::SUCCESS;
        }

        if ($this->option('migrate')) {
            $this->migrateExistingUploads($linkPath, $storageReal);
            $this->forceClearDirectory($linkPath);
        }

        if ($this->ensureSymlink($linkPath, $storageReal)) {
            $this->info("Uploads symlink created: {$linkPath} -> {$storageReal}");

            return self::SUCCESS;
        }

        return $this->finishWithoutSymlink($linkPath, $storageReal);
    }

    private function finishWithoutSymlink(string $linkPath, string $storageReal): int
    {
        if (is_dir($linkPath) && !is_link($linkPath)) {
            $backupPath = $linkPath.'_backup_'.date('YmdHis');

            if (@rename($linkPath, $backupPath)) {
                $this->warn("Renamed blocking uploads folder to {$backupPath}");
            }
        }

        $this->newLine();
        $this->warn('Symlink is not available on this server (PHP symlink() disabled on Hostinger).');
        $this->info('This is OK — your setup will still work.');
        $this->newLine();
        $this->info("Persistent storage: {$storageReal}");
        $this->info('Public URLs: /uploads/... are served by Laravel (UploadServeController).');
        $this->info('Keep UPLOADS_DISK_ROOT in .env pointing at the path above.');
        $this->newLine();
        $this->comment('Optional SSH shortcut (if you have terminal access):');
        $this->line("  ln -sfn {$storageReal} {$linkPath}");

        return self::SUCCESS;
    }

    private function ensureSymlink(string $linkPath, string $targetPath): bool
    {
        if (is_link($linkPath)) {
            $currentTarget = realpath($linkPath);

            if ($currentTarget === $targetPath) {
                $this->info("Uploads symlink already points to {$targetPath}");

                return true;
            }

            if (!unlink($linkPath)) {
                $this->error("Could not remove stale uploads symlink: {$linkPath}");

                return false;
            }
        } elseif (is_dir($linkPath)) {
            $this->warn("{$linkPath} is a real directory, not a symlink.");
            $this->warn('Run with --migrate to move files into persistent storage, then delete this folder and re-run.');

            if (!$this->option('migrate')) {
                return false;
            }

            if (!$this->directoryIsEmpty($linkPath)) {
                // finishWithoutSymlink() will rename the folder when symlink is unavailable.
                return false;
            }

            if (!rmdir($linkPath)) {
                $this->error("Could not remove empty uploads directory: {$linkPath}");

                return false;
            }
        } elseif (file_exists($linkPath) && !unlink($linkPath)) {
            $this->error("Could not remove blocking file at uploads link path: {$linkPath}");

            return false;
        }

        if (!function_exists('symlink')) {
            return $this->tryShellSymlink($linkPath, $targetPath);
        }

        if (\symlink($targetPath, $linkPath)) {
            return true;
        }

        return $this->tryShellSymlink($linkPath, $targetPath);
    }

    private function tryShellSymlink(string $linkPath, string $targetPath): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        if (!function_exists('exec')) {
            return false;
        }

        $command = sprintf('ln -sfn %s %s 2>&1', escapeshellarg($targetPath), escapeshellarg($linkPath));
        exec($command, $output, $exitCode);

        if ($exitCode === 0 && is_link($linkPath)) {
            $this->info('Uploads symlink created via shell ln -s');

            return true;
        }

        return false;
    }

    private function migrateExistingUploads(string $linkPath, string $targetPath): void
    {
        if (!is_dir($linkPath) || is_link($linkPath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($linkPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $migrated = 0;

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($this->normalizePath($linkPath)) + 1);
            $destination = $targetPath.DIRECTORY_SEPARATOR.$relativePath;

            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0755, true);
                }

                continue;
            }

            if (file_exists($destination)) {
                // Already in persistent storage (e.g. app wrote directly to UPLOADS_DISK_ROOT).
                unlink($item->getPathname());

                continue;
            }

            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }

            if (!rename($item->getPathname(), $destination)) {
                if (@copy($item->getPathname(), $destination)) {
                    unlink($item->getPathname());
                    $migrated++;
                }

                continue;
            }

            $migrated++;
        }

        $this->cleanupEmptyDirectories($linkPath);

        if ($migrated > 0) {
            $this->info("Migrated {$migrated} upload file(s) into persistent storage.");
        }
    }

    private function forceClearDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        $htaccess = $path.DIRECTORY_SEPARATOR.'.htaccess';
        if (is_file($htaccess)) {
            @unlink($htaccess);
        }
    }

    private function cleanupEmptyDirectories(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && $this->directoryIsEmpty($item->getPathname())) {
                rmdir($item->getPathname());
            }
        }
    }

    private function directoryIsEmpty(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        return count(scandir($path)) === 2;
    }

    private function writeHtaccess(string $directory): void
    {
        $htaccess = $directory.DIRECTORY_SEPARATOR.'.htaccess';

        if (file_exists($htaccess)) {
            return;
        }

        file_put_contents($htaccess, <<<'HTACCESS'
# Serve uploaded files directly; never route through Laravel.
<IfModule mod_rewrite.c>
    RewriteEngine Off
</IfModule>

Options -Indexes

# Hostinger LiteSpeed can cache 404 responses; keep uploads out of page cache.
<IfModule LiteSpeed>
    CacheDisable public /uploads/
</IfModule>

<IfModule mod_headers.c>
    <FilesMatch "\.(jpg|jpeg|png|webp|gif|mp4|mov|avi|webm|pdf)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
HTACCESS);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return rtrim($path, '/');
    }
}
