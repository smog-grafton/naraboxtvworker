<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class FfmpegTranscodeService
{
    public function faststartMp4(string $inputPath): ?string
    {
        $ffmpeg = config('media_worker.ffmpeg_bin', 'ffmpeg');
        if (! is_file($inputPath)) {
            return null;
        }
        $dir = dirname($inputPath);
        $base = pathinfo($inputPath, PATHINFO_FILENAME);
        $outputPath = $dir . '/' . $base . '_play.mp4';
        $result = Process::timeout(7200)->run([
            $ffmpeg, '-y', '-i', $inputPath, '-c', 'copy', '-movflags', '+faststart', $outputPath,
        ]);
        if (! $result->successful() || ! is_file($outputPath)) {
            Log::warning('FfmpegTranscodeService: faststart failed', ['input' => $inputPath, 'stderr' => $result->errorOutput()]);
            return null;
        }
        return $outputPath;
    }
}
