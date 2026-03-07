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
    ],

    'portal' => [
        'api_base_url' => rtrim(env('PORTAL_API_BASE_URL', 'https://portal.naraboxtv.com'), '/'),
        'api_token' => env('PORTAL_API_TOKEN', ''),
    ],

];
