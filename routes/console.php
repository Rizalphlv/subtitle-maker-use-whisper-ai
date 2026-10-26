<?php

use App\Models\ApiClient;
use App\Models\SubtitleJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('subtitle-api:client {name}', function (string $name) {
    $token = 'sat_'.Str::random(48);
    ApiClient::create(['name' => $name, 'token_hash' => hash('sha256', $token)]);
    $this->warn('Store this token securely; it will not be shown again:');
    $this->line($token);
})->purpose('Create a mobile API client and issue a one-time bearer token');

Artisan::command('subtitle-jobs:cleanup', function () {
    $completedBefore = now()->subDays((int) config('subtitle_api.output_retention_days'));
    SubtitleJob::where('status', 'completed')->where('completed_at', '<', $completedBefore)->each(function (SubtitleJob $job) {
        if ($job->output_path) {
            Storage::disk($job->output_disk)->delete($job->output_path);
        }
    });

    $failedBefore = now()->subDays((int) config('subtitle_api.failed_retention_days'));
    SubtitleJob::where('status', 'failed')->where('failed_at', '<', $failedBefore)->each(function (SubtitleJob $job) {
        Storage::disk($job->input_disk)->delete($job->input_path);
    });
})->purpose('Delete expired subtitle job artifacts');

Schedule::command('subtitle-jobs:cleanup')->daily()->withoutOverlapping();
