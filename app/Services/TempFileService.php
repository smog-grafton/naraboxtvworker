<?php

namespace App\Services;

use Illuminate\Support\Str;

class TempFileService
{
    public function tempDir(): string
    {
        $dir = config('media_worker.temp_dir', storage_path('app/worker-temp'));
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return rtrim($dir, '/');
    }

    public function pathForRequest(string $externalId, string $suffix = ''): string
    {
        $base = $this->tempDir() . '/' . Str::slug($externalId);
        if ($suffix !== '') {
            return $base . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $suffix);
        }

        return $base;
    }

    /** Directory path for HLS output (worker creates it and zips it for CDN upload). */
    public function hlsDirForRequest(string $externalId): string
    {
        return $this->pathForRequest($externalId, 'hls');
    }

    /**
     * Remove all temp files/dirs for this request so the worker does not run out of space.
     * Call after callback to CDN (success or failure).
     */
    public function cleanupForRequest(string $externalId): void
    {
        $dir = $this->tempDir();
        $prefix = Str::slug($externalId);
        if ($prefix === '') {
            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (str_starts_with($name, $prefix)) {
                $path = $dir . '/' . $name;
                if (is_file($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    $this->removeDirRecursive($path);
                }
            }
        }
    }

    private function removeDirRecursive(string $path): void
    {
        $items = @scandir($path);
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            if (is_dir($child)) {
                $this->removeDirRecursive($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
