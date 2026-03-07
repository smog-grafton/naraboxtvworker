<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FfmpegTestCommand extends Command
{
    protected $signature = 'ffmpeg:test';

    protected $description = 'Run ffmpeg -version to verify FFmpeg is available for the worker.';

    public function handle(): int
    {
        $ffmpegBin = config('media_worker.ffmpeg_bin', 'ffmpeg');
        $ffprobeBin = config('media_worker.ffprobe_bin', 'ffprobe');

        $this->info('Testing FFmpeg availability for media worker.');
        $this->newLine();

        $ffmpegOk = $this->runBin($ffmpegBin, 'ffmpeg');
        if (! $ffmpegOk) {
            $this->error('FFmpeg check failed. Install ffmpeg or set FFMPEG_BIN in .env.');
            return self::FAILURE;
        }

        $this->newLine();
        $ffprobeOk = $this->runBin($ffprobeBin, 'ffprobe');
        if (! $ffprobeOk) {
            $this->warn('FFprobe check failed. Set FFPROBE_BIN in .env.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('FFmpeg and FFprobe are available. Worker is ready for media processing.');
        return self::SUCCESS;
    }

    private function runBin(string $bin, string $label): bool
    {
        $this->line('Running: ' . $bin . ' -version');
        $output = [];
        $exitCode = 0;
        @exec(escapeshellarg($bin) . ' -version 2>&1', $output, $exitCode);
        $out = implode("\n", $output);

        if ($exitCode !== 0 || $out === '') {
            $this->error('  [FAIL] ' . $label . ' exit code ' . $exitCode);
            if ($out !== '') {
                $this->line($out);
            }
            return false;
        }

        $firstLine = explode("\n", $out)[0] ?? $out;
        $this->info('  [OK] ' . $firstLine);
        return true;
    }
}
