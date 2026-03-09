<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FfmpegTranscodeService
{
    /** Default HLS profiles: label => [height, bitrate, audio_bitrate] */
    private const HLS_PROFILES = [
        '1080p' => ['height' => 1080, 'bitrate' => 5500000, 'audio_bitrate' => '192k'],
        '720p' => ['height' => 720, 'bitrate' => 2800000, 'audio_bitrate' => '128k'],
        '480p' => ['height' => 480, 'bitrate' => 1200000, 'audio_bitrate' => '96k'],
    ];
    public function probe(string $localPath): array
    {
        if (! is_file($localPath)) {
            return [];
        }

        $ffprobe = config('media_worker.ffprobe_bin', 'ffprobe');
        $process = new Process([
            $ffprobe,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $localPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('FfmpegTranscodeService: probe failed', [
                'path' => $localPath,
                'exit_code' => $process->getExitCode(),
                'output' => $process->getErrorOutput(),
            ]);
            return [];
        }

        $json = $process->getOutput();
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return [];
        }

        $format = $data['format'] ?? [];
        $streams = $data['streams'] ?? [];
        $video = null;
        foreach ($streams as $s) {
            if (($s['codec_type'] ?? '') === 'video') {
                $video = $s;
                break;
            }
        }

        return [
            'duration' => (float) ($format['duration'] ?? 0),
            'size' => (int) ($format['size'] ?? 0),
            'format_name' => $format['format_name'] ?? null,
            'codec_name' => $video['codec_name'] ?? null,
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
        ];
    }

    public function faststart(string $inputPath, string $outputPath): bool
    {
        if (! is_file($inputPath)) {
            Log::warning('FfmpegTranscodeService: faststart input missing', ['input' => $inputPath]);
            return false;
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $ffmpeg = config('media_worker.ffmpeg_bin', 'ffmpeg');
        $process = new Process([
            $ffmpeg,
            '-y',
            '-i', $inputPath,
            '-c', 'copy',
            '-movflags', '+faststart',
            $outputPath,
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('FfmpegTranscodeService: faststart failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'exit_code' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            return false;
        }

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            Log::warning('FfmpegTranscodeService: faststart produced empty or missing output', [
                'output' => $outputPath,
            ]);
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            return false;
        }

        return true;
    }

    /**
     * Generate HLS variants into a directory. Directory must exist and be writable.
     * Creates variant subdirs (e.g. 1080p/, 720p/, 480p/) with index.m3u8 + segments, and master.m3u8 at root.
     *
     * @return array{qualities_json: array<int, array{id: string, label: string, height: int, width: int|null, bandwidth: int, path: string}>, success: bool}
     */
    public function generateHls(string $inputPath, string $hlsDir): array
    {
        if (! is_file($inputPath)) {
            Log::warning('FfmpegTranscodeService: generateHls input missing', ['input' => $inputPath]);
            return ['qualities_json' => [], 'success' => false];
        }

        $ffmpeg = config('media_worker.ffmpeg_bin', 'ffmpeg');
        $ffprobe = config('media_worker.ffprobe_bin', 'ffprobe');

        $sourceHeight = $this->probeHeight($ffprobe, $inputPath);
        $hasAudio = $this->probeHasAudio($ffprobe, $inputPath);

        $profiles = $this->resolveProfiles($sourceHeight);
        if ($profiles === []) {
            $profiles = [['label' => 'source', 'height' => max(240, (int) ($sourceHeight ?: 480)), 'bitrate' => 900000, 'audio_bitrate' => '96k']];
        }

        if (! is_dir($hlsDir)) {
            @mkdir($hlsDir, 0755, true);
        }

        $generated = [];
        foreach ($profiles as $profile) {
            $label = $profile['label'];
            $height = $profile['height'];
            $audioBitrate = $profile['audio_bitrate'];

            $variantDir = $hlsDir . '/' . $label;
            if (! is_dir($variantDir)) {
                @mkdir($variantDir, 0755, true);
            }
            $playlistPath = $variantDir . '/index.m3u8';
            $segmentPattern = $variantDir . '/segment_%05d.ts';

            $ok = $this->runHlsVariant($ffmpeg, $inputPath, $playlistPath, $segmentPattern, $height, $audioBitrate, $hasAudio);
            if (! $ok || ! is_file($playlistPath)) {
                Log::warning('FfmpegTranscodeService: HLS variant failed', ['profile' => $label]);
                continue;
            }

            $generated[] = [
                'id' => $label,
                'label' => strtoupper($label),
                'height' => $height,
                'width' => (int) round((16 / 9) * $height),
                'bandwidth' => (int) ($profile['bitrate'] ?? 900000),
                'path' => $label . '/index.m3u8',
            ];
        }

        if ($generated === []) {
            $fallbackDir = $hlsDir . '/source';
            @mkdir($fallbackDir, 0755, true);
            $fallbackPlaylist = $fallbackDir . '/index.m3u8';
            $fallbackSegment = $fallbackDir . '/segment_%05d.ts';
            $h = max(240, (int) ($sourceHeight ?: 480));
            if ($this->runHlsVariant($ffmpeg, $inputPath, $fallbackPlaylist, $fallbackSegment, $h, '96k', $hasAudio) && is_file($fallbackPlaylist)) {
                $generated[] = [
                    'id' => 'source',
                    'label' => 'SOURCE',
                    'height' => $h,
                    'width' => (int) round((16 / 9) * $h),
                    'bandwidth' => 900000,
                    'path' => 'source/index.m3u8',
                ];
            }
        }

        if ($generated === []) {
            Log::warning('FfmpegTranscodeService: no HLS variants generated');
            return ['qualities_json' => [], 'success' => false];
        }

        usort($generated, fn (array $a, array $b): int => (int) $b['height'] <=> (int) $a['height']);

        $masterPath = $hlsDir . '/master.m3u8';
        $masterLines = ['#EXTM3U', '#EXT-X-VERSION:3'];
        foreach ($generated as $v) {
            $masterLines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d',
                max(1, (int) $v['bandwidth']),
                (int) $v['width'],
                (int) $v['height']
            );
            $masterLines[] = $v['path'];
        }
        if (@file_put_contents($masterPath, implode("\n", $masterLines) . "\n") === false) {
            return ['qualities_json' => [], 'success' => false];
        }

        return ['qualities_json' => $generated, 'success' => true];
    }

    private function probeHeight(string $ffprobe, string $inputPath): ?int
    {
        $process = new Process([
            $ffprobe,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=height',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $inputPath,
        ]);
        $process->setTimeout(15);
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }
        $value = trim($process->getOutput());
        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    private function probeHasAudio(string $ffprobe, string $inputPath): bool
    {
        $process = new Process([
            $ffprobe,
            '-v', 'error',
            '-select_streams', 'a',
            '-show_entries', 'stream=index',
            '-of', 'csv=p=0',
            $inputPath,
        ]);
        $process->setTimeout(15);
        $process->run();
        if (! $process->isSuccessful()) {
            return true;
        }
        return trim($process->getOutput()) !== '';
    }

    /** @return list<array{label: string, height: int, bitrate: int, audio_bitrate: string}> */
    private function resolveProfiles(?int $sourceHeight): array
    {
        $out = [];
        foreach (self::HLS_PROFILES as $label => $p) {
            $h = $p['height'];
            if ($sourceHeight !== null && $sourceHeight > 0 && $h > $sourceHeight) {
                continue;
            }
            $out[] = [
                'label' => $label,
                'height' => $h,
                'bitrate' => $p['bitrate'],
                'audio_bitrate' => $p['audio_bitrate'],
            ];
        }
        return $out;
    }

    private function runHlsVariant(
        string $ffmpeg,
        string $inputPath,
        string $playlistPath,
        string $segmentPattern,
        int $height,
        string $audioBitrate,
        bool $hasAudio
    ): bool {
        $args = [
            $ffmpeg,
            '-y',
            '-i', $inputPath,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-vf', "scale=-2:{$height}:force_original_aspect_ratio=decrease",
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '22',
        ];
        if ($hasAudio) {
            $args[] = '-c:a';
            $args[] = 'aac';
            $args[] = '-b:a';
            $args[] = $audioBitrate;
        } else {
            $args[] = '-an';
        }
        $args = array_merge($args, [
            '-f', 'hls',
            '-hls_time', '6',
            '-hls_playlist_type', 'vod',
            '-hls_flags', 'independent_segments',
            '-hls_segment_filename', $segmentPattern,
            $playlistPath,
        ]);

        $process = new Process($args);
        $process->setTimeout(7200);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('FfmpegTranscodeService: HLS variant ffmpeg failed', [
                'height' => $height,
                'error' => $process->getErrorOutput(),
            ]);
            return false;
        }
        return is_file($playlistPath) && filesize($playlistPath) > 0;
    }
}
