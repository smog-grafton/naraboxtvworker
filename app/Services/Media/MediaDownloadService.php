<?php

namespace App\Services\Media;

use App\Models\ProcessingRequest;
use Illuminate\Support\Facades\Log;

class MediaDownloadService
{
    public function download(ProcessingRequest $request): ?string
    {
        $url = $request->source_url;
        if (! $url) {
            Log::warning('MediaDownloadService: no source_url', ['request_id' => $request->id]);
            return null;
        }
        $tempDir = config('media_worker.temp_dir');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }
        $filename = $request->original_filename ?: basename(parse_url($url, PHP_URL_PATH) ?: 'source.mp4');
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'source.mp4';
        $localPath = rtrim($tempDir, '/') . '/' . $request->external_id . '_' . $filename;
        Log::info('MediaDownloadService: download placeholder', ['request_id' => $request->id]);
        return $localPath;
    }

    public function cleanup(ProcessingRequest $request): void
    {
        $paths = $request->artifact_paths ?? [];
        if (! is_array($paths)) {
            return;
        }
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
