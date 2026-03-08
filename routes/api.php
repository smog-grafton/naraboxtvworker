<?php

use App\Http\Controllers\Api\ProcessingRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('worker.api')
    ->group(function (): void {
        Route::post('/processing/submit', [ProcessingRequestController::class, 'submit']);
        Route::get('/processing/{externalId}', [ProcessingRequestController::class, 'status'])
            ->where('externalId', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
        Route::post('/processing/{externalId}/retry', [ProcessingRequestController::class, 'retry'])
            ->where('externalId', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
    });
