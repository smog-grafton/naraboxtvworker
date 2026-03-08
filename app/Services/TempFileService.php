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
}
