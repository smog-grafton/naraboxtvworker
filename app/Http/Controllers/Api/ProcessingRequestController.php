<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProcessingRequestStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Transcode\ProcessMediaPipelineJob;
use App\Models\ProcessingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProcessingRequestController extends Controller
{
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cdn_asset_id' => ['nullable', 'string', 'max:64'],
            'cdn_source_id' => ['nullable', 'integer', 'min:1'],
            'source_url' => ['required', 'string', 'url', 'max:2048'],
            'original_filename' => ['nullable', 'string', 'max:512'],
            'callback_url' => ['nullable', 'string', 'url', 'max:2048'],
            'portal_sync_hint' => ['nullable', 'string', 'max:512'],
            'payload' => ['nullable', 'array'],
        ]);

        $processingRequest = ProcessingRequest::create([
            'cdn_asset_id' => $validated['cdn_asset_id'] ?? null,
            'cdn_source_id' => $validated['cdn_source_id'] ?? null,
            'source_url' => $validated['source_url'],
            'original_filename' => $validated['original_filename'] ?? null,
            'status' => ProcessingRequestStatus::Received,
            'callback_url' => $validated['callback_url'] ?? null,
            'portal_sync_hint' => $validated['portal_sync_hint'] ?? null,
            'payload' => $validated['payload'] ?? null,
        ]);

        ProcessMediaPipelineJob::dispatch($processingRequest);

        return response()->json([
            'success' => true,
            'data' => [
                'external_id' => $processingRequest->external_id,
                'status' => $processingRequest->status->value,
                'received_at' => $processingRequest->received_at?->toIso8601String(),
            ],
        ], 202);
    }

    public function status(string $externalId): JsonResponse
    {
        $request = ProcessingRequest::where('external_id', $externalId)->with(['attempts', 'callbackLogs', 'syncLogs'])->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'external_id' => $request->external_id,
                'status' => $request->status,
                'failure_reason' => $request->failure_reason,
                'received_at' => $request->received_at?->toIso8601String(),
                'started_at' => $request->started_at?->toIso8601String(),
                'completed_at' => $request->completed_at?->toIso8601String(),
                'attempts_count' => $request->attempts->count(),
                'callback_logs_count' => $request->callbackLogs->count(),
                'sync_logs_count' => $request->syncLogs->count(),
            ],
        ]);
    }

    public function retry(string $externalId): JsonResponse
    {
        $request = ProcessingRequest::where('external_id', $externalId)->firstOrFail();

        if (! in_array($request->status, [ProcessingRequestStatus::Failed, ProcessingRequestStatus::Cancelled], true)) {
            return response()->json([
                'success' => false,
                'error' => 'Only failed or cancelled requests can be retried.',
            ], 422);
        }

        $request->update([
            'status' => ProcessingRequestStatus::Received,
            'failure_reason' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        ProcessMediaPipelineJob::dispatch($request->fresh());

        return response()->json([
            'success' => true,
            'data' => [
                'external_id' => $request->external_id,
                'status' => ProcessingRequestStatus::Received->value,
            ],
        ], 202);
    }
}
