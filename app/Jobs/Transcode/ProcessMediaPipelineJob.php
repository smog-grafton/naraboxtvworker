<?php

namespace App\Jobs\Transcode;

use App\Enums\ProcessingRequestStatus;
use App\Models\ProcessingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMediaPipelineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 7200;

    public function __construct(
        public ProcessingRequest $processingRequest
    ) {
        $this->onQueue(config('media_worker.queues.transcode', 'transcode'));
    }

    public function handle(): void
    {
        $request = $this->processingRequest->fresh();
        if (! $request || $request->isFinished()) {
            return;
        }

        $request->update([
            'status' => ProcessingRequestStatus::Downloading,
            'started_at' => $request->started_at ?? now(),
        ]);

        // TODO: chain download -> probe -> transcode -> HLS -> upload -> callback -> sync
        // For Phase 1 we only advance status and leave a placeholder
        Log::info('ProcessMediaPipelineJob: pipeline placeholder', [
            'external_id' => $request->external_id,
            'request_id' => $request->id,
        ]);

        $request->update([
            'status' => ProcessingRequestStatus::Downloaded,
        ]);
    }
}
