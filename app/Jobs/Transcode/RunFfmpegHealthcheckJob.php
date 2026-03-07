<?php

namespace App\Jobs\Transcode;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunFfmpegHealthcheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct()
    {
        $this->onQueue(config('media_worker.queues.transcode', 'transcode'));
    }

    public function handle(): void
    {
        $ffmpegBin = config('media_worker.ffmpeg_bin', 'ffmpeg');
        $ffprobeBin = config('media_worker.ffprobe_bin', 'ffprobe');

        $result = [
            'ffmpeg' => $this->probeBin($ffmpegBin),
            'ffprobe' => $this->probeBin($ffprobeBin),
            'at' => now()->toDateTimeString(),
        ];

        Log::channel('stack')->info('FFmpeg healthcheck job completed', $result);
    }

    private function probeBin(string $bin): array
    {
        $output = [];
        $exitCode = -1;
        @exec(escapeshellarg($bin) . ' -version 2>&1', $output, $exitCode);

        return [
            'bin' => $bin,
            'exit_code' => $exitCode,
            'ok' => $exitCode === 0 && implode('', $output) !== '',
            'first_line' => $output[0] ?? null,
        ];
    }
}
