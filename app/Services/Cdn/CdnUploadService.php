<?php

namespace App\Services\Cdn;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CdnUploadService
{
    public function baseUrl(): string
    {
        return (string) config('media_worker.cdn.api_base_url', '');
    }

    public function token(): string
    {
        return (string) config('media_worker.cdn.api_token', '');
    }

    /**
     * Upload optimized MP4 (and optional HLS zip) to CDN worker upload endpoint.
     *
     * @return array{optimized_path: string, hls_master_path?: string|null, qualities_json?: array|null}
     * @throws \RuntimeException on upload failure
     */
    public function uploadOptimized(
        string $assetId,
        int $sourceId,
        string $localPath,
        ?string $hlsZipPath = null
    ): array {
        $base = $this->baseUrl();
        $url = $base ? rtrim($base, '/') . '/api/v1/media/worker/upload' : '';
        $token = $this->token();

        if ($url === '' || $token === '') {
            throw new \RuntimeException('CdnUploadService: CDN base URL or token not set');
        }

        if (! is_file($localPath)) {
            throw new \RuntimeException('CdnUploadService: optimized file not found: ' . $localPath);
        }

        $http = Http::withToken($token)->timeout(600)
            ->attach('optimized', fopen($localPath, 'r'), 'optimized.mp4');

        if ($hlsZipPath !== null && is_file($hlsZipPath)) {
            $http = $http->attach('hls_zip', fopen($hlsZipPath, 'r'), 'hls.zip');
        }

        $request = $http->post($url, [
            'asset_id' => $assetId,
            'source_id' => (string) $sourceId,
        ]);

        if (! $request->successful()) {
            $body = $request->json();
            $message = is_array($body) && isset($body['error']) ? (string) $body['error'] : $request->body();
            $full = sprintf('HTTP %d: %s', $request->status(), $message ?: $request->reason());
            Log::warning('CdnUploadService: upload failed', [
                'asset_id' => $assetId,
                'source_id' => $sourceId,
                'status' => $request->status(),
                'body' => $message,
            ]);
            throw new \RuntimeException('CDN upload failed: ' . $full);
        }

        $data = $request->json();
        if (! is_array($data) || ! isset($data['data'])) {
            throw new \RuntimeException('CDN upload returned invalid response');
        }

        return $data['data'];
    }
}
