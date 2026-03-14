<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FfmpegTranscodeService
{
    /** Last error message from probe/faststart/generateHls (for failure_reason). */
    public ?string $lastError = null;

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
            $this->lastError = $this->tailStderr($process->getErrorOutput()) ?: 'Probe failed (exit ' . $process->getExitCode() . ')';
            Log::warning('FfmpegTranscodeService: probe failed', [
                'path' => $localPath,
                'exit_code' => $process->getExitCode(),
                'output' => $process->getErrorOutput(),
            ]);
            return [];
        }
        $this->lastError = null;

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
            '-loglevel', 'error',
            '-i', $inputPath,
            '-c', 'copy',
            '-movflags', '+faststart',
            $outputPath,
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->lastError = $this->tailStderr($process->getErrorOutput()) ?: 'Faststart failed (exit ' . $process->getExitCode() . ')';
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
            $this->lastError = 'Faststart produced empty or missing output file.';
            Log::warning('FfmpegTranscodeService: faststart produced empty or missing output', [
                'output' => $outputPath,
            ]);
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            return false;
        }

        $this->lastError = null;
        return true;
    }

    /**
     * Generate HLS variants into a directory. Directory must exist and be writable.
     * Creates variant subdirs (e.g. 1080p/, 720p/, 480p/) with index.m3u8 + segments, and master.m3u8 at root.
     *
     * @return array{
     *     qualities_json: array<int, array{id: string, label: string, height: int, width: int|null, bandwidth: int, path: string}>,
     *     success: bool,
     *     quality_status?: string
     * }
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
        $requestedProfilesCount = count($profiles);
        if ($profiles === []) {
            $profiles = [[
                'label' => 'source',
                'height' => max(240, (int) ($sourceHeight ?: 480)),
                'bitrate' => 900000,
                'audio_bitrate' => '96k',
            ]];
            $requestedProfilesCount = max($requestedProfilesCount, 1);
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

            if (! $this->validateVariantPlaylist($playlistPath)) {
                Log::warning('FfmpegTranscodeService: HLS variant validation failed', ['profile' => $label]);
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
            if (
                $this->runHlsVariant($ffmpeg, $inputPath, $fallbackPlaylist, $fallbackSegment, $h, '96k', $hasAudio)
                && is_file($fallbackPlaylist)
                && $this->validateVariantPlaylist($fallbackPlaylist)
            ) {
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
            $this->lastError = 'No HLS variants generated (check FFmpeg logs).';
            Log::warning('FfmpegTranscodeService: no HLS variants generated');
            return ['qualities_json' => [], 'success' => false];
        }
        $this->lastError = null;

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
            $this->lastError = 'Failed to write master.m3u8.';
            return ['qualities_json' => [], 'success' => false];
        }

        if (! $this->validateMasterPlaylist($masterPath, $generated)) {
            $this->lastError = 'Master playlist validation failed.';
            Log::warning('FfmpegTranscodeService: master playlist validation failed', ['master' => $masterPath]);
            return ['qualities_json' => [], 'success' => false];
        }

        $qualityStatus = 'completed';
        if ($requestedProfilesCount > 0 && count($generated) < $requestedProfilesCount) {
            $qualityStatus = 'partial';
        }

        return [
            'qualities_json' => $generated,
            'success' => true,
            'quality_status' => $qualityStatus,
        ];
    }

    /**
     * Extract the useful part of FFmpeg/ffprobe stderr (real error; skip banner and progress).
     */
    private function tailStderr(?string $stderr): string
    {
        if (! is_string($stderr) || trim($stderr) === '') {
            return '';
        }
        $lines = preg_split('/\r\n|\r|\n/', $stderr) ?: [];
        $bannerPatterns = [
            '/^ffmpeg version/i',
            '/^ffprobe version/i',
            '/^built with /i',
            '/^configuration:/i',
            '/^\s*--/',
            '/^libav/i',
            '/copyright/i',
        ];
        $progressPattern = '/^\s*frame=\s*\d+|^\s*fps=|\bq=-?\d+\.\d+|size=\s*\d+[KMG]?iB|time=\d|bitrate=|\bspeed=\s*[\d.]+x|\belapsed=/i';
        $errorKeywords = ['error', 'invalid', 'failed', 'could not', 'cannot', 'no such', 'invalid argument', 'does not contain', 'not found'];
        $errorLines = [];
        $otherLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            foreach ($bannerPatterns as $p) {
                if (preg_match($p, $line)) {
                    $line = '';
                    break;
                }
            }
            if ($line === '' || preg_match($progressPattern, $line)) {
                continue;
            }
            $lower = strtolower($line);
            $isError = false;
            foreach ($errorKeywords as $k) {
                if (str_contains($lower, $k)) {
                    $isError = true;
                    break;
                }
            }
            if ($isError) {
                $errorLines[] = $line;
            } else {
                $otherLines[] = $line;
            }
        }
        if ($errorLines !== []) {
            return implode(' ', array_slice($errorLines, -5));
        }
        if ($otherLines !== []) {
            return implode(' ', array_slice($otherLines, -10));
        }
        return trim(substr($stderr, -600));
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
            '-loglevel', 'error',
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
            $this->lastError = $this->tailStderr($process->getErrorOutput()) ?: 'HLS variant failed (exit ' . $process->getExitCode() . ')';
            Log::warning('FfmpegTranscodeService: HLS variant ffmpeg failed', [
                'height' => $height,
                'error' => $process->getErrorOutput(),
            ]);
            return false;
        }
        return is_file($playlistPath) && filesize($playlistPath) > 0;
    }

    private function validateVariantPlaylist(string $playlistPath): bool
    {
        $dir = dirname($playlistPath);
        $contents = @file_get_contents($playlistPath);
        if ($contents === false || $contents === '') {
            return false;
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $segmentCount = 0;

        for ($i = 0, $len = count($lines); $i < $len; $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#') === false) {
                continue;
            }

            if (str_starts_with($line, '#EXTINF')) {
                // Next non-comment, non-empty line should be the segment URI.
                $j = $i + 1;
                while ($j < $len && (trim($lines[$j]) === '' || str_starts_with(trim($lines[$j]), '#'))) {
                    $j++;
                }
                if ($j >= $len) {
                    continue;
                }
                $segment = trim($lines[$j]);
                if ($segment === '') {
                    continue;
                }
                $segmentPath = $dir . '/' . $segment;
                if (! is_file($segmentPath) || filesize($segmentPath) === 0) {
                    Log::warning('FfmpegTranscodeService: missing or empty HLS segment', [
                        'playlist' => $playlistPath,
                        'segment' => $segmentPath,
                    ]);
                    return false;
                }
                $segmentCount++;
            }
        }

        return $segmentCount > 0;
    }

    /**
     * @param array<int, array{id: string, label: string, height: int, width: int|null, bandwidth: int, path: string}> $variants
     */
    private function validateMasterPlaylist(string $masterPath, array $variants): bool
    {
        if (! is_file($masterPath) || filesize($masterPath) === 0) {
            return false;
        }

        $contents = @file_get_contents($masterPath);
        if ($contents === false || $contents === '') {
            return false;
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $variantPaths = array_column($variants, 'path');
        $seenStreams = 0;
        $expectedNext = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                $seenStreams++;
                $expectedNext = true;
                continue;
            }

            if ($expectedNext === true) {
                $expectedNext = null;
                if (! in_array($line, $variantPaths, true)) {
                    Log::warning('FfmpegTranscodeService: master references unknown variant', [
                        'master' => $masterPath,
                        'line' => $line,
                    ]);
                    return false;
                }
            }
        }

        return $seenStreams > 0;
    }
}
