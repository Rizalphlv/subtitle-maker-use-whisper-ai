<?php

return [
    'input_disk' => env('SUBTITLE_INPUT_DISK', 'minio'),
    'output_disk' => env('SUBTITLE_OUTPUT_DISK', 'minio'),
    'presign_disk' => env('SUBTITLE_PRESIGN_DISK', 'minio_presign'),
    'max_upload_kb' => (int) env('SUBTITLE_MAX_UPLOAD_KB', 102400),
    'allowed_mimes' => ['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/x-flac', 'audio/ogg', 'application/ogg'],
    'allowed_extensions' => ['mp3', 'm4a', 'mp4', 'wav', 'flac', 'ogg', 'oga'],
    'poll_interval_seconds' => (int) env('SUBTITLE_POLL_INTERVAL_SECONDS', 60),
    'signed_url_minutes' => (int) env('SUBTITLE_SIGNED_URL_MINUTES', 60),
    'input_retention_hours' => (int) env('SUBTITLE_INPUT_RETENTION_HOURS', 24),
    'output_retention_days' => (int) env('SUBTITLE_OUTPUT_RETENTION_DAYS', 30),
    'failed_retention_days' => (int) env('SUBTITLE_FAILED_RETENTION_DAYS', 7),
    // Higher attempt allowance prevents lock-contention releases from losing a
    // legitimate delivery; max_exceptions still caps real processing failures.
    'tries' => (int) env('SUBTITLE_JOB_TRIES', 10),
    'max_exceptions' => (int) env('SUBTITLE_JOB_MAX_EXCEPTIONS', 3),
    'backoff' => [60, 300, 900],
    'timeout' => (int) env('SUBTITLE_JOB_TIMEOUT', 7200),
    'lock_seconds' => (int) env('SUBTITLE_JOB_LOCK_SECONDS', 7500),
    'lock_release_seconds' => (int) env('SUBTITLE_JOB_LOCK_RELEASE_SECONDS', 900),
];
