<?php

use App\Jobs\Transcode\RunFfmpegHealthcheckJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Optional: dispatch a healthcheck job on schedule to prove queue is working (disable in production if not needed)
// Schedule::job(new RunFfmpegHealthcheckJob)->hourly();
