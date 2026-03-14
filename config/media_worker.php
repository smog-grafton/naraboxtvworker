<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Worker-specific settings for transcode, probe, and sync. Used by jobs
    | and services. Keep environment-driven for Coolify/deployment.
    |
    */

    'temp_dir' => env('WORKER_TEMP_DIR', storage_path('app/worker-temp')),

    'download' => [
        'timeout' => env('WORKER_DOWNLOAD_TIMEOUT', 600),
        'connect_timeout' => env('WORKER_DOWNLOAD_CONNECT_TIMEOUT', 30),
        'retry_times' => env('WORKER_DOWNLOAD_RETRY_TIMES', 3),
        'retry_sleep_ms' => env('WORKER_DOWNLOAD_RETRY_SLEEP_MS', 500),
    ],

    'ffmpeg_bin' => env('FFMPEG_BIN', 'ffmpeg'),
    'ffprobe_bin' => env('FFPROBE_BIN', 'ffprobe'),

    'queues' => [
        'transcode' => env('TRANSCODE_QUEUE', 'transcode'),
        'probe' => env('PROBE_QUEUE', 'probe'),
        'sync' => env('SYNC_QUEUE', 'sync'),
    ],

    'cdn' => [
        'api_base_url' => rtrim(env('CDN_API_BASE_URL', 'https://cdn.naraboxtv.com'), '/'),
        'api_token' => env('CDN_API_TOKEN', ''),
        'callback_timeout' => (int) env('CDN_CALLBACK_TIMEOUT', 90),
        'callback_connect_timeout' => (int) env('CDN_CALLBACK_CONNECT_TIMEOUT', 15),
    ],

    'artifacts' => [
        'enabled' => env('WORKER_ARTIFACTS_ENABLED', true),
        'ttl_minutes' => env('WORKER_ARTIFACTS_TTL_MINUTES', 60),
        'cleanup_batch_size' => env('WORKER_ARTIFACTS_CLEANUP_BATCH_SIZE', 100),
    ],

    'portal' => [
        'api_base_url' => rtrim(env('PORTAL_API_BASE_URL', 'https://portal.naraboxtv.com'), '/'),
        'api_token' => env('PORTAL_API_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker API (incoming)
    |--------------------------------------------------------------------------
    | Token for CDN/Portal to call this worker's API. Bearer token.
    */
    'api_token' => env('WORKER_API_TOKEN', ''),
];
