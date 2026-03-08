<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Process;

class MediaProbeService
{
    public function probe(string $localPath): ?array
    {
        if (! $localPath || ! is_file($localPath)) {
            return null;
        }
        $ffprobe = config('media_worker.ffprobe_bin', 'ffprobe');
        $result = Process::run([
            $ffprobe, '-v', 'quiet', '-print_format', 'json',
            '-show_format', '-show_streams', $localPath,
        ]);
        if (! $result->successful()) {
            return null;
        }
        $json = json_decode($result->output(), true);
        return is_array($json) ? $json : null;
    }
}
