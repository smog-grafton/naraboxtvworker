<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallbackLog extends Model
{
    protected $fillable = [
        'processing_request_id',
        'direction',
        'target',
        'url',
        'response_code',
        'response_body',
        'error_message',
    ];

    public function processingRequest(): BelongsTo
    {
        return $this->belongsTo(ProcessingRequest::class);
    }
}
