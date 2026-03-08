<?php

namespace App\Services\Cdn;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PortalApiService
{
    public function baseUrl(): string
    {
        return (string) config('media_worker.portal.api_base_url', '');
    }

    public function token(): string
    {
        return (string) config('media_worker.portal.api_token', '');
    }

    /**
     * Sync playback availability to Portal (e.g. update video source references).
     *
     * @param  array<string, mixed>  $payload
     */
    public function syncPlayback(string $hint, array $payload): array
    {
        $url = $this->baseUrl() . '/api/v1/worker/sync';
        $token = $this->token();
        if ($url === '/api/v1/worker/sync' || $token === '') {
            Log::warning('PortalApiService: base URL or token not set');

            return ['success' => false, 'response_code' => 0, 'body' => null];
        }

        $response = Http::withToken($token)
            ->timeout(30)
            ->post($url, array_merge(['hint' => $hint], $payload));

        return [
            'success' => $response->successful(),
            'response_code' => $response->status(),
            'body' => $response->json(),
        ];
    }
}
