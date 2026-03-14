<?php

namespace App\Services\Cdn;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CdnApiService
{
    public function baseUrl(): string
    {
        return (string) config('media_worker.cdn.api_base_url', '');
    }

    public function token(): string
    {
        return (string) config('media_worker.cdn.api_token', '');
    }

    /** @param array<string, mixed> $payload */
    public function notifyResult(string $assetId, int $sourceId, array $payload): array
    {
        $base = $this->baseUrl();
        $url = $base ? rtrim($base, '/') . '/api/v1/media/worker/callback' : '';
        $token = $this->token();
        if ($url === '' || $token === '') {
            Log::warning('CdnApiService: base URL or token not set');
            return ['success' => false, 'response_code' => 0, 'body' => null];
        }
        $timeout = max(15, (int) config('media_worker.cdn.callback_timeout', 90));
        $connectTimeout = max(5, (int) config('media_worker.cdn.callback_connect_timeout', 15));
        $response = Http::withToken($token)
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->post($url, array_merge(
                ['asset_id' => $assetId, 'source_id' => $sourceId],
                $payload
            ));
        return [
            'success' => $response->successful(),
            'response_code' => $response->status(),
            'body' => $response->json(),
        ];
    }
}
