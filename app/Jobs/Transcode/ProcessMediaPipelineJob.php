<?php

namespace App\Jobs\Transcode;

use App\Enums\ProcessingRequestStatus;
use App\Models\CallbackLog;
use App\Models\HlsArtifact;
use App\Models\ProcessingRequest;
use App\Services\Cdn\CdnApiService;
use App\Services\Media\FfmpegTranscodeService;
use App\Services\Media\MediaDownloadService;
use App\Services\TempFileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class ProcessMediaPipelineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 7200;

    public function __construct(
        public ProcessingRequest $processingRequest
    ) {
        $this->onQueue(config('media_worker.queues.transcode', 'transcode'));
    }

    public function handle(
        MediaDownloadService $downloadService,
        TempFileService $tempFileService,
        FfmpegTranscodeService $transcodeService,
        CdnApiService $cdnApi
    ): void {
        $request = $this->processingRequest->fresh();
        if (! $request || $request->isFinished()) {
            return;
        }

        $request->update([
            'status' => ProcessingRequestStatus::Downloading,
            'started_at' => $request->started_at ?? now(),
        ]);

        try {
            $downloadedPath = $downloadService->download($request);
            if ($downloadedPath === null) {
                $this->failRequest($request, 'Download failed', $cdnApi);
                return;
            }

            $request->update(['status' => ProcessingRequestStatus::Probing]);
            $probe = $transcodeService->probe($downloadedPath);
            if ($probe !== []) {
                Log::info('ProcessMediaPipelineJob: probe result', [
                    'request_id' => $request->id,
                    'probe' => $probe,
                ]);
            }

            $optimizedPath = $tempFileService->pathForRequest($request->external_id, 'optimized.mp4');
            $request->update(['status' => ProcessingRequestStatus::Transcoding]);
            if (! $transcodeService->faststart($downloadedPath, $optimizedPath)) {
                $this->failRequest($request, 'Faststart (transcode) failed', $cdnApi);
                return;
            }

            $paths = $request->artifact_paths ?? [];
            if (! is_array($paths)) {
                $paths = [];
            }
            $paths[] = $optimizedPath;
            $request->artifact_paths = $paths;
            $request->save();

            $request->update(['status' => ProcessingRequestStatus::Transcoding]);
            $hlsDir = $tempFileService->hlsDirForRequest($request->external_id);
            $hlsResult = $transcodeService->generateHls($optimizedPath, $hlsDir);
            if (! ($hlsResult['success'] ?? false)) {
                $this->failRequest($request, 'HLS generation failed', $cdnApi);
                return;
            }

            $hlsZipPath = $tempFileService->pathForRequest($request->external_id, 'hls.zip');
            if (! $this->zipDirectory($hlsDir, $hlsZipPath)) {
                $this->failRequest($request, 'Failed to create HLS zip', $cdnApi);
                return;
            }
            $paths = $request->artifact_paths ?? [];
            if (! is_array($paths)) {
                $paths = [];
            }
            $paths[] = $hlsZipPath;
            $request->artifact_paths = $paths;
            $request->save();

            $qualitiesJson = $hlsResult['qualities_json'] ?? [];
            $qualityStatus = $hlsResult['quality_status'] ?? 'completed';

            $artifactConfig = config('media_worker.artifacts', []);
            $ttlMinutes = (int) ($artifactConfig['ttl_minutes'] ?? 60);

            $artifact = HlsArtifact::updateOrCreate(
                ['processing_request_id' => $request->id],
                [
                    'external_id' => $request->external_id,
                    'cdn_asset_id' => $request->cdn_asset_id,
                    'cdn_source_id' => $request->cdn_source_id,
                    'status' => 'artifact_ready',
                    'quality_status' => $qualityStatus,
                    'qualities_json' => $qualitiesJson,
                    'hls_dir' => $hlsDir,
                    'zip_path' => $hlsZipPath,
                    'zip_size_bytes' => is_file($hlsZipPath) ? filesize($hlsZipPath) : null,
                    'download_token' => $this->generateDownloadToken(),
                    'download_expires_at' => now()->addMinutes($ttlMinutes > 0 ? $ttlMinutes : 60),
                ]
            );

            $artifactDownloadUrl = url("/api/v1/artifacts/{$artifact->download_token}");

            $request->update(['status' => ProcessingRequestStatus::Uploading]);

            $payload = [
                'status' => $qualityStatus === 'completed' ? 'completed' : 'partial',
                'artifact_download_url' => $artifactDownloadUrl,
                'artifact_expires_at' => $artifact->download_expires_at?->toIso8601String(),
                'quality_status' => $qualityStatus,
                'qualities_json' => $qualitiesJson,
                'is_faststart' => true,
                'playback_type' => 'hls',
                'external_id' => $request->external_id,
            ];

            $result = $cdnApi->notifyResult($request->cdn_asset_id, (int) $request->cdn_source_id, $payload);
            $this->logCallback($request, $cdnApi->baseUrl() . '/api/v1/media/worker/callback', $result);

            $request->update([
                'status' => ProcessingRequestStatus::Completed,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessMediaPipelineJob: exception', [
                'request_id' => $request->id ?? null,
                'message' => $e->getMessage(),
            ]);
            $this->failRequest($request, $e->getMessage(), $cdnApi);
        } finally {
            $req = $this->processingRequest->fresh();
            if ($req) {
                $downloadService->cleanup($req);
                $tempFileService->cleanupForRequest($req->external_id);
            }
        }
    }

    private function failRequest(ProcessingRequest $request, string $reason, CdnApiService $cdnApi): void
    {
        $request->update([
            'status' => ProcessingRequestStatus::Failed,
            'failure_reason' => $reason,
        ]);

        $payload = [
            'status' => 'failed',
            'failure_reason' => $reason,
        ];
        $result = $cdnApi->notifyResult($request->cdn_asset_id, (int) $request->cdn_source_id, $payload);
        $this->logCallback($request, $cdnApi->baseUrl() . '/api/v1/media/worker/callback', $result);
    }

    private function logCallback(ProcessingRequest $request, string $url, array $result): void
    {
        CallbackLog::create([
            'processing_request_id' => $request->id,
            'direction' => 'outbound',
            'target' => 'cdn',
            'url' => $url,
            'response_code' => $result['response_code'] ?? null,
            'response_body' => $result['body'] !== null ? json_encode($result['body']) : null,
            'error_message' => ($result['success'] ?? false) ? null : ('HTTP ' . ($result['response_code'] ?? 0)),
        ]);
    }

    private function zipDirectory(string $dirPath, string $zipPath): bool
    {
        if (! is_dir($dirPath)) {
            return false;
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $rootLength = strlen(rtrim($dirPath, DIRECTORY_SEPARATOR)) + 1;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }
            $relative = substr($path, $rootLength);
            if ($relative === false || $relative === '') {
                continue;
            }
            $zip->addFile($path, $relative);
        }
        $zip->close();
        return is_file($zipPath) && filesize($zipPath) > 0;
    }

    private function generateDownloadToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
