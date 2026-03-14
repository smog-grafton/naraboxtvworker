<?php

namespace App\Services\Media;

use App\Models\ProcessingRequest;
use App\Services\TempFileService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MediaDownloadService
{
    /** User-Agent matching CDN remote fetcher so download scripts (e.g. mobifliks) accept the request. */
    private const USER_AGENT = 'NaraboxWorker/1.0 (compatible; NaraboxCDNImporter/1.0)';

    public function __construct(
        private readonly TempFileService $tempFileService
    ) {}

    /**
     * Download source_url to a local file. Uses same strategy as naraboxtv-cdn remote fetch:
     * browser-like User-Agent, follow redirects, retries, optional IPv4/IPv6 fallback.
     *
     * @return string path to downloaded file
     * @throws RuntimeException on any failure (message is persisted as failure_reason)
     */
    public function download(ProcessingRequest $request): string
    {
        $url = $request->source_url;
        if (! $url || trim($url) === '') {
            Log::warning('MediaDownloadService: no source_url', ['request_id' => $request->id]);
            throw new RuntimeException('Download failed: no source URL.');
        }

        $localPath = $this->tempFileService->pathForRequest($request->external_id, 'source.mp4');
        $dir = dirname($localPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $downloadConfig = config('media_worker.download', []);
        $timeout = max(60, (int) ($downloadConfig['timeout'] ?? 600));
        $connectTimeout = max(10, (int) ($downloadConfig['connect_timeout'] ?? 30));
        $retryTimes = max(0, (int) ($downloadConfig['retry_times'] ?? 3));
        $retrySleepMs = max(0, (int) ($downloadConfig['retry_sleep_ms'] ?? 500));

        $attempts = [
            ['label' => 'default', 'options' => []],
            ['label' => 'force_ipv4', 'options' => ['force_ip_resolve' => 'v4']],
            ['label' => 'force_ipv6', 'options' => ['force_ip_resolve' => 'v6']],
        ];

        $lastError = null;

        foreach ($attempts as $attempt) {
            try {
                $this->attemptDownload(
                    $url,
                    $localPath,
                    $timeout,
                    $connectTimeout,
                    $retryTimes,
                    $retrySleepMs,
                    $attempt['options']
                );

                if (! is_file($localPath) || filesize($localPath) === 0) {
                    throw new RuntimeException('Download failed: remote server returned empty or missing file.');
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
                $lastError = $e;
                Log::warning('MediaDownloadService: download attempt failed', [
                    'request_id' => $request->id,
                    'attempt' => $attempt['label'],
                    'host' => parse_url($url, PHP_URL_HOST),
                    'message' => $e->getMessage(),
                ]);
                if (is_file($localPath)) {
                    @unlink($localPath);
                }
                // If it's already our RuntimeException with a clear message, try next attempt only for connection/timeout
                if ($e instanceof RuntimeException && ! $this->isRetryableMessage($e->getMessage())) {
                    throw $e;
                }
            }
        }

        $message = $lastError instanceof \Throwable
            ? $lastError->getMessage()
            : 'Download failed: unknown error.';
        throw new RuntimeException($message);
    }

    private function attemptDownload(
        string $url,
        string $localPath,
        int $timeout,
        int $connectTimeout,
        int $retryTimes,
        int $retrySleepMs,
        array $extraOptions
    ): void {
        $client = Http::retry($retryTimes, $retrySleepMs, throw: false)
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => '*/*',
            ])
            ->withOptions(array_merge([
                'sink' => $localPath,
            ], $extraOptions));

        $response = $client->get($url);

        if (! $response->successful()) {
            if (is_file($localPath)) {
                @unlink($localPath);
            }
            $status = $response->status();
            $bodyPreview = '';
            try {
                $body = $response->body();
                if (is_string($body) && $body !== '') {
                    $bodyPreview = substr(preg_replace('/\s+/', ' ', $body), 0, 200);
                }
            } catch (\Throwable) {
            }
            $msg = $bodyPreview !== ''
                ? sprintf('Download failed: HTTP %d. Response: %s', $status, $bodyPreview)
                : sprintf('Download failed: HTTP %d.', $status);
            throw new RuntimeException($msg);
        }
    }

    private function isRetryableMessage(string $message): bool
    {
        $m = strtolower($message);
        return str_contains($m, 'curl error 28')
            || str_contains($m, 'curl error 7')
            || str_contains($m, 'connection timed out')
            || str_contains($m, 'failed to connect')
            || str_contains($m, 'couldn\'t connect')
            || str_contains($m, 'transfer closed')
            || str_contains($m, 'unexpected eof');
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
