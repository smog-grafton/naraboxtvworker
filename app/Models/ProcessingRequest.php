<?php

namespace App\Models;

use App\Enums\ProcessingRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProcessingRequest extends Model
{
    protected $fillable = [
        'external_id',
        'cdn_asset_id',
        'cdn_source_id',
        'source_url',
        'original_filename',
        'status',
        'failure_reason',
        'payload',
        'artifact_paths',
        'callback_url',
        'portal_sync_hint',
        'received_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProcessingRequestStatus::class,
            'payload' => 'array',
            'artifact_paths' => 'array',
            'received_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProcessingRequest $request) {
            if (empty($request->external_id)) {
                $request->external_id = (string) Str::uuid();
            }
            if (empty($request->received_at)) {
                $request->received_at = now();
            }
        });
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ProcessingAttempt::class)->orderBy('id');
    }

    public function callbackLogs(): HasMany
    {
        return $this->hasMany(CallbackLog::class)->orderBy('id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class)->orderBy('id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            ProcessingRequestStatus::Completed,
            ProcessingRequestStatus::Failed,
            ProcessingRequestStatus::Cancelled,
        ], true);
    }
}
