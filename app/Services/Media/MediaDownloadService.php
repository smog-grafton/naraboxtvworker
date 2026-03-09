<?php

namespace App\Services\Media;

use App\Models\ProcessingRequest;
use App\Services\TempFileService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MediaDownloadService
{
    public function __construct(
        private readonly TempFileService $tempFileService
    ) {}

    public function download(ProcessingRequest $request): ?string
    {
        $url = $request->source_url;
        if (! $url) {
            Log::warning('MediaDownloadService: no source_url', ['request_id' => $request->id]);
            return null;
        }

        $localPath = $this->tempFileService->pathForRequest($request->external_id, 'source.mp4');
        $dir = dirname($localPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $downloadConfig = config('media_worker.download', []);
        $timeout = (int) ($downloadConfig['timeout'] ?? 600);
        $connectTimeout = (int) ($downloadConfig['connect_timeout'] ?? 30);
        $retryTimes = max(0, (int) ($downloadConfig['retry_times'] ?? 3));
        $retrySleepMs = max(0, (int) ($downloadConfig['retry_sleep_ms'] ?? 500));

        try {
            $client = Http::retry($retryTimes, $retrySleepMs)
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withOptions(['sink' => $localPath])
                ->accept('*/*');

            $response = $client->get($url);

            if (! $response->successful()) {
                Log::warning('MediaDownloadService: download failed', [
                    'request_id' => $request->id,
                    'status' => $response->status(),
                ]);
                if (is_file($localPath)) {
                    @unlink($localPath);
                }
                return null;
            }

            if (! is_file($localPath) || filesize($localPath) === 0) {
                Log::warning('MediaDownloadService: download produced empty or missing file', [
                    'request_id' => $request->id,
                    'path' => $localPath,
                ]);
                if (is_file($localPath)) {
                    @unlink($localPath);
                }
                return null;
            }

            $paths = $request->artifact_paths ?? [];
            if (! is_array($paths)) {
                $paths = [];
            }
            $paths[] = $localPath;
            $request->artifact_paths = $paths;
            $request->save();

            Log::info('MediaDownloadService: download completed', [
                'request_id' => $request->id,
                'path' => $localPath,
                'size' => filesize($localPath),
            ]);
            return $localPath;
        } catch (\Throwable $e) {
            Log::warning('MediaDownloadService: download exception', [
                'request_id' => $request->id,
                'message' => $e->getMessage(),
            ]);
            if (is_file($localPath)) {
                @unlink($localPath);
            }
            return null;
        }
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
