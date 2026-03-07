<?php

use Illuminate\Support\Str;

return [

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => env('HORIZON_USE', 'default'),

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
        'redis:'.env('TRANSCODE_QUEUE', 'transcode') => 300,
        'redis:'.env('PROBE_QUEUE', 'probe') => 60,
        'redis:'.env('SYNC_QUEUE', 'sync') => 120,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],
    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,
    'memory_limit' => (int) env('HORIZON_MEMORY_LIMIT', 64),

    'defaults' => [
        'transcode-supervisor' => [
            'connection' => 'redis',
            'queue' => [env('TRANSCODE_QUEUE', 'transcode')],
            'balance' => 'simple',
            'maxProcesses' => (int) env('HORIZON_TRANSCODE_PROCESSES', 2),
            'maxTime' => (int) env('HORIZON_TRANSCODE_MAX_TIME', 7200),
            'maxJobs' => 0,
            'memory' => (int) env('HORIZON_TRANSCODE_MEMORY', 512),
            'tries' => (int) env('HORIZON_TRANSCODE_TRIES', 3),
            'timeout' => (int) env('HORIZON_TRANSCODE_TIMEOUT', 7200),
            'nice' => 0,
        ],
        'probe-supervisor' => [
            'connection' => 'redis',
            'queue' => [env('PROBE_QUEUE', 'probe')],
            'balance' => 'simple',
            'maxProcesses' => (int) env('HORIZON_PROBE_PROCESSES', 4),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 2,
            'timeout' => 300,
            'nice' => 0,
        ],
        'sync-supervisor' => [
            'connection' => 'redis',
            'queue' => [env('SYNC_QUEUE', 'sync')],
            'balance' => 'simple',
            'maxProcesses' => (int) env('HORIZON_SYNC_PROCESSES', 2),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 2,
            'timeout' => 600,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'transcode-supervisor' => [
                'maxProcesses' => (int) env('HORIZON_TRANSCODE_PROCESSES', 2),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'probe-supervisor' => [
                'maxProcesses' => (int) env('HORIZON_PROBE_PROCESSES', 4),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'sync-supervisor' => [
                'maxProcesses' => (int) env('HORIZON_SYNC_PROCESSES', 2),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
        'local' => [
            'transcode-supervisor' => [
                'maxProcesses' => 1,
            ],
            'probe-supervisor' => [
                'maxProcesses' => 2,
            ],
            'sync-supervisor' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config',
        'database',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],

];
