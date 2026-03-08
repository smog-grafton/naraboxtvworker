<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;

class HlsGenerationService
{
    /** @return array<string> */
    public function generate(string $inputPath, string $outputDir): array
    {
        if (! is_file($inputPath)) {
            return [];
        }
        if (! is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }
        Log::info('HlsGenerationService: placeholder', ['input' => $inputPath, 'output_dir' => $outputDir]);
        return [];
    }
}
