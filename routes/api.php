<?php

use App\Http\Controllers\Api\SubtitleJobController;
use App\Http\Controllers\ChunkUploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.client', 'throttle:subtitle-api'])->group(function () {
    Route::post('/subtitle-jobs', [SubtitleJobController::class, 'store']);
    Route::get('/subtitle-jobs/{jobId}/download', [SubtitleJobController::class, 'download'])
        ->where('jobId', 'sub_[A-Za-z0-9_-]+')
        ->name('api.subtitle-jobs.download');
    Route::get('/subtitle-jobs/{jobId}', [SubtitleJobController::class, 'show'])->where('jobId', 'sub_[A-Za-z0-9_-]+');
});

Route::middleware('throttle:120,1')->group(function () {
    // Upload a single chunk.
    Route::post('/upload/chunk', [ChunkUploadController::class, 'store']);

    // Poll upload status by upload_id.
    Route::get('/upload/{uploadId}/status', [ChunkUploadController::class, 'status'])
        ->where('uploadId', '[0-9a-f\-]{36}');
});
