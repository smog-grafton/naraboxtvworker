<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HlsArtifact;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HlsArtifactController extends Controller
{
    public function show(string $token, Request $request): BinaryFileResponse|\Illuminate\Http\Response
    {
        $artifact = HlsArtifact::where('download_token', $token)->first();
        if (! $artifact) {
            return response()->json(['message' => 'Artifact not found'], 404);
        }

        if ($artifact->download_expires_at && $artifact->download_expires_at->isPast()) {
            return response()->json(['message' => 'Artifact expired'], 410);
        }

        $path = $artifact->zip_path;
        if (! is_string($path) || ! is_file($path)) {
            return response()->json(['message' => 'Artifact file missing'], 410);
        }

        $artifact->last_fetched_at = now();
        $artifact->save();

        return response()->file($path, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="hls_artifact_'.$artifact->external_id.'.zip"',
        ]);
    }

    public function ack(string $externalId): \Illuminate\Http\JsonResponse
    {
        $artifact = \App\Models\HlsArtifact::where('external_id', $externalId)->first();
        if (! $artifact) {
            return response()->json(['message' => 'Artifact not found'], 404);
        }
        $artifact->update(['status' => 'fetched_by_cdn', 'last_fetched_at' => now()]);
        return response()->json(['updated' => true, 'status' => 'fetched_by_cdn']);
    }
}

