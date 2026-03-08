<?php

namespace App\Console\Commands;

use App\Jobs\Transcode\RunFfmpegHealthcheckJob;
use Illuminate\Console\Command;

class DispatchHealthcheckJobCommand extends Command
{
    protected $signature = 'worker:dispatch-healthcheck';

    protected $description = 'Dispatch the FFmpeg healthcheck job to the transcode queue (no tinker required).';

    public function handle(): int
    {
        RunFfmpegHealthcheckJob::dispatch();

        $this->info('Healthcheck job dispatched to the transcode queue. Check application logs for "FFmpeg healthcheck job completed".');

        return self::SUCCESS;
    }
}
