<?php

namespace App\Services\Media;

/**
 * Resolves a safe video filename/extension from a remote URL (e.g. mobifliks downloadmp4.php?file=...).
 * Mirrors naraboxtv-cdn ImportRemoteMediaSourceJob::resolveRemoteFilename so we never use
 * the script path (e.g. .php) as the file extension.
 */
class RemoteFilenameResolver
{
    private const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', 'mpeg', 'mpg', 'ts'];

    /**
     * Resolve a safe extension for the downloaded file (e.g. "mp4" from ?file=path/Title.mp4).
     * Never returns "php" or other non-video extensions from the URL path.
     */
    public function resolveExtension(string $sourceUrl, ?string $contentType = null): string
    {
        $fromQuery = $this->extensionFromQueryString($sourceUrl);
        if ($fromQuery !== null) {
            return $fromQuery;
        }

        $urlPath = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($urlPath, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::VIDEO_EXTENSIONS, true)) {
            return $ext;
        }

        return $this->extensionFromMimeType($contentType) ?? 'mp4';
    }

    /**
     * Preferred basename for the source file (for temp file naming). Uses query param "file" etc.
     */
    public function resolveBasename(string $sourceUrl, string $fallbackId): string
    {
        $query = (string) parse_url($sourceUrl, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $params);
            foreach (['file', 'filename', 'name', 'title', 'download', 'url', 'path'] as $key) {
                $value = $params[$key] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $candidate = $this->sanitizeBasename(basename($value));
                if ($candidate !== '' && $this->hasVideoExtension($candidate)) {
                    return $candidate;
                }
            }
        }

        $urlPath = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $base = $this->sanitizeBasename(basename($urlPath));
        if ($base !== '' && $this->hasVideoExtension($base)) {
            return $base;
        }

        return 'source-' . $fallbackId;
    }

    private function extensionFromQueryString(string $sourceUrl): ?string
    {
        $query = (string) parse_url($sourceUrl, PHP_URL_QUERY);
        if ($query === '') {
            return null;
        }
        parse_str($query, $params);
        foreach (['file', 'filename', 'name', 'title', 'download', 'url', 'path'] as $key) {
            $value = $params[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $ext = strtolower((string) pathinfo(basename($value), PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, self::VIDEO_EXTENSIONS, true)) {
                return $ext;
            }
        }
        return null;
    }

    private function hasVideoExtension(string $filename): bool
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::VIDEO_EXTENSIONS, true);
    }

    private function sanitizeBasename(?string $filename): string
    {
        if (! is_string($filename) || trim($filename) === '') {
            return '';
        }
        $decoded = urldecode($filename);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $decoded) ?: '';
        $clean = ltrim($clean, '.');
        return $clean;
    }

    private function extensionFromMimeType(?string $mimeType): ?string
    {
        if (! is_string($mimeType) || trim($mimeType) === '') {
            return null;
        }
        $normalized = trim(strtolower(explode(';', $mimeType)[0]));
        return match ($normalized) {
            'video/mp4' => 'mp4',
            'video/x-m4v' => 'm4v',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv',
            'video/mpeg' => 'mpeg',
            'video/mp2t' => 'ts',
            default => null,
        };
    }
}
