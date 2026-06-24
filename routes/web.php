<?php

use App\Http\Controllers\UploadController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('upload.create');
});

// Video Library & Player Routes
Route::group(['prefix' => 'videos', 'as' => 'video.'], function () {
    Route::get('/', [VideoController::class, 'index'])->name('index');
    Route::get('/{video}', [VideoController::class, 'show'])->name('show');
    Route::get('/{video}/stream', [VideoController::class, 'stream'])->name('stream');
    Route::get('/{video}/subtitle/{language}', [VideoController::class, 'subtitle'])->name('subtitle');
});

// Upload UI Routes
Route::group(['prefix' => 'upload', 'as' => 'upload.'], function () {
    Route::get('/', [UploadController::class, 'create'])->name('create');
    Route::post('/', [UploadController::class, 'store'])->name('store');
    
    Route::get('/{uploadId}', [UploadController::class, 'status'])->name('status');
    Route::get('/{uploadId}/status', [UploadController::class, 'checkStatus'])->name('check_status');
    Route::get('/{uploadId}/download', [UploadController::class, 'download'])->name('download');
});
