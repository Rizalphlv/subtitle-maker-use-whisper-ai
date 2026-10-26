# Audio subtitle REST API

## Setup

Run `php artisan migrate`, then provision each mobile/backend client with
`php artisan subtitle-api:client "Android production"`. The command prints the bearer token once; distribute it through a user/session provisioning service or secure device storage. Do not compile a shared unrestricted token into an APK.

The Web UI keeps its existing `redis` worker and retry settings. Mobile audio
jobs use the isolated `audio_subtitles` connection and `audio-subtitles` queue:

```bash
php artisan queue:work audio_subtitles --queue=audio-subtitles --tries=10 --timeout=7200
php artisan schedule:work
```

Set `SUBTITLE_AUDIO_QUEUE_RETRY_AFTER=7800`, which exceeds both the 7200-second
worker/job timeout and the 7500-second overlap-lock lifetime. The lock releases
colliding deliveries after 900 seconds instead of deleting them. Ten delivery
attempts accommodate lock releases, while `SUBTITLE_JOB_MAX_EXCEPTIONS=3` caps
real processing failures. The container has PCNTL support; restart only the
mobile audio worker after changing these values.

FFmpeg must be installed. `AWS_ENDPOINT` remains the internal MinIO endpoint used
by the Web UI and workers. The separate `minio_presign` disk signs mobile URLs
directly against `AWS_PUBLIC_ENDPOINT`; URLs are never rewritten after signing.
Set that value to the externally reachable HTTPS reverse-proxy/S3 origin and set
`AWS_PUBLIC_USE_PATH_STYLE_ENDPOINT` to match the proxy/bucket layout. DNS, TLS,
the Host header, bucket path, and query string must reach MinIO unchanged because
they participate in SigV4. The bucket must remain private. Code-level signing is
tested locally, but external reachability requires a real off-network device test.

## API

All requests require `Authorization: Bearer <client-token>` and are client-owned.

`POST /api/v1/subtitle-jobs` accepts multipart fields `file` (required), `language` (`auto` by default or a BCP-47-like language code), and `output_format` (`srt` only). Accepted audio containers are MP3, M4A/MP4 audio, WAV, FLAC, and Ogg, subject to both extension and server-detected MIME checks. The default maximum is 100 MB; align `SUBTITLE_MAX_UPLOAD_KB` with PHP `upload_max_filesize`/`post_max_size` and proxy limits.

```bash
curl -X POST https://api.example.com/api/v1/subtitle-jobs \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@speech.m4a" -F "language=auto" -F "output_format=srt"
```

Success is `202` with `job_id`, `status: queued`, and `poll_interval_seconds`.
Validation is `422`, missing/invalid authentication is `401`, and acceptance/storage failure is `503`.

`GET /api/v1/subtitle-jobs/{job_id}` returns `queued`, `processing`, `completed`, or `failed`, plus its current stage. Completed responses contain a newly generated temporary URL and UTC expiry; failed responses contain only a stable safe error code/message. Unknown jobs and jobs belonging to another client both return `404`.

```bash
curl -H "Authorization: Bearer $TOKEN" \
  https://api.example.com/api/v1/subtitle-jobs/sub_example
```

## Processing and retention

The worker skips video extraction and reuses the existing 120-second FFmpeg chunking, Whisper-1 transcription, timestamp normalization, contextual English translation, and SRT generator. Thus the current pipeline still produces English SRT; the submitted `language` is retained as request metadata while Whisper auto-detection remains unchanged.

Successful input is deleted after verified result upload. Failed input is retained for `SUBTITLE_FAILED_RETENTION_DAYS`; output is retained for `SUBTITLE_OUTPUT_RETENTION_DAYS`. The daily scheduler removes expired objects. Database job records remain as an audit/status record. Calling status again refreshes an expired signed URL.

For a complete check: upload a short audio file, run the worker, poll until `completed`, and fetch the signed URL from a network outside Docker. Never use paid-provider calls in automated tests; fake HTTP/storage there.
