<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingAttempt extends Model
{
    protected $fillable = [
        'processing_request_id',
        'stage',
        'status',
        'log_output',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function processingRequest(): BelongsTo
    {
        return $this->belongsTo(ProcessingRequest::class);
    }
}
