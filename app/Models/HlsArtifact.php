<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HlsArtifact extends Model
{
    protected $fillable = [
        'processing_request_id',
        'external_id',
        'cdn_asset_id',
        'cdn_source_id',
        'status',
        'quality_status',
        'qualities_json',
        'hls_dir',
        'zip_path',
        'zip_size_bytes',
        'download_token',
        'download_expires_at',
        'last_fetched_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'qualities_json' => 'array',
            'download_expires_at' => 'datetime',
            'last_fetched_at' => 'datetime',
        ];
    }

    public function processingRequest(): BelongsTo
    {
        return $this->belongsTo(ProcessingRequest::class);
    }
}

