<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $fillable = [
        'processing_request_id',
        'target',
        'action',
        'response_code',
        'response_body',
        'error_message',
    ];

    public function processingRequest(): BelongsTo
    {
        return $this->belongsTo(ProcessingRequest::class);
    }
}
