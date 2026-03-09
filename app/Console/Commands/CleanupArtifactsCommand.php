<?php

namespace App\Console\Commands;

use App\Models\HlsArtifact;
use App\Services\TempFileService;
use Illuminate\Console\Command;

class CleanupArtifactsCommand extends Command
{
    protected $signature = 'worker:cleanup-artifacts';

    protected $description = 'Mark expired HLS artifacts and remove their ZIP files and temp dirs';

    public function handle(TempFileService $tempFileService): int
    {
        $batchSize = (int) config('media_worker.artifacts.cleanup_batch_size', 100);
        $query = HlsArtifact::whereIn('status', ['artifact_ready', 'fetched_by_cdn'])
            ->whereNotNull('download_expires_at')
            ->where('download_expires_at', '<', now())
            ->limit($batchSize);

        $count = 0;
        $query->get()->each(function (HlsArtifact $artifact) use ($tempFileService, &$count): void {
            $zipPath = $artifact->zip_path;
            if (is_string($zipPath) && $zipPath !== '' && is_file($zipPath)) {
                @unlink($zipPath);
            }
            $tempFileService->cleanupForRequest($artifact->external_id);
            $artifact->update(['status' => 'expired']);
            $count++;
        });

        if ($count > 0) {
            $this->info("Cleaned up {$count} expired artifact(s).");
        }

        return self::SUCCESS;
    }
}
