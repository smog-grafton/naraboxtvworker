<?php

namespace App\Console\Commands;

use App\Services\TempFileService;
use Illuminate\Console\Command;

class WorkerDebugTempCommand extends Command
{
    protected $signature = 'worker:debug-temp {external_id? : Optional processing request external_id (UUID) to show paths for}';

    protected $description = 'Show where the worker saves temp files and check writability (for Coolify/debug).';

    public function handle(TempFileService $tempFileService): int
    {
        $tempDir = config('media_worker.temp_dir', storage_path('app/worker-temp'));
        $storageBase = storage_path('app');

        $this->info('=== Worker temp file location ===');
        $this->line('WORKER_TEMP_DIR (env): ' . (getenv('WORKER_TEMP_DIR') ?: '(not set)'));
        $this->line('Resolved temp_dir: ' . $tempDir);
        $this->line('storage_path("app"): ' . $storageBase);
        $this->newLine();

        $this->info('=== Writability checks ===');
        $baseExists = is_dir($tempDir);
        $this->line('Temp dir exists: ' . ($baseExists ? 'yes' : 'NO'));
        if ($baseExists) {
            $writable = is_writable($tempDir);
            $this->line('Temp dir writable: ' . ($writable ? 'yes' : 'NO'));
            $free = @disk_free_space($tempDir);
            $this->line('Disk free (temp dir): ' . ($free !== false ? round($free / 1024 / 1024, 1) . ' MB' : 'unknown'));
        } else {
            $parent = dirname($tempDir);
            $this->line('Parent exists: ' . (is_dir($parent) ? 'yes' : 'NO'));
            $this->line('Parent writable: ' . (is_writable($parent) ? 'yes' : 'NO'));
        }

        $externalId = $this->argument('external_id') ?? '62c15d03-55bb-4006-9fd0-778978a4e7c0';
        $this->newLine();
        $this->info('=== Paths for external_id: ' . $externalId . ' ===');
        $requestDir = $tempFileService->requestDir($externalId);
        $sourcePath = $tempFileService->pathForRequest($externalId, 'source.mp4');
        $optimizedPath = $tempFileService->pathForRequest($externalId, 'optimized.mp4');
        $hlsDir = $tempFileService->hlsDirForRequest($externalId);
        $this->line('Request dir: ' . $requestDir);
        $this->line('Source path: ' . $sourcePath);
        $this->line('Optimized path (faststart output): ' . $optimizedPath);
        $this->line('HLS dir: ' . $hlsDir);
        $this->newLine();

        $this->info('=== Test write ===');
        $testFile = $requestDir . '/.worker-debug-temp-write-test';
        $wrote = @file_put_contents($testFile, 'ok');
        if ($wrote !== false) {
            @unlink($testFile);
            $this->line('Created and removed test file in request dir: OK');
        } else {
            $this->error('Could not write test file to: ' . $testFile);
            $this->line('Check permissions and that the filesystem is writable (e.g. in Coolify set WORKER_TEMP_DIR to a writable path like /tmp/worker-temp).');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('If faststart still fails with "No such file or directory", set WORKER_TEMP_DIR in Coolify to a path that exists and is writable (e.g. /tmp/worker-temp).');
        return self::SUCCESS;
    }
}
