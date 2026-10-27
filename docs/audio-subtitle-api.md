# Audio subtitle REST API

## Setup

Run `php artisan migrate`, then provision each mobile/backend client with
`php artisan subtitle-api:client "Android production"`. The command prints the
bearer token once; distribute it through a user/session provisioning service or
secure device storage. Do not compile a shared unrestricted token into an APK.

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
by the Web UI and workers. MinIO API and console ports remain private on the
Docker network. Mobile clients do not connect to MinIO and do not receive S3
presigned URLs; completed SRT files are streamed through the authenticated
Laravel API.

## API

All requests require `Authorization: Bearer <client-token>` and are client-owned.

`POST /api/v1/subtitle-jobs` accepts multipart fields `file` (required),
`language` (`auto` by default or a BCP-47-like language code), and
`output_format` (`srt` only). Accepted audio containers are MP3, M4A/MP4 audio,
WAV, FLAC, and Ogg, subject to both extension and server-detected MIME checks.
The default maximum is 100 MB; align `SUBTITLE_MAX_UPLOAD_KB` with PHP
`upload_max_filesize`/`post_max_size` and proxy limits.

```bash
curl -X POST https://api.example.com/api/v1/subtitle-jobs \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@speech.m4a" -F "language=auto" -F "output_format=srt"
```

Success is `202` with `job_id`, `status: queued`, and
`poll_interval_seconds`. Validation is `422`, missing/invalid authentication is
`401`, and acceptance/storage failure is `503`.

`GET /api/v1/subtitle-jobs/{job_id}` returns `queued`, `processing`, `completed`,
or `failed`, plus its current stage. A completed response keeps the existing
`download_url` field, but the URL points to the authenticated Laravel download
route and `expires_at` is `null`. It is not a public URL: clients must send the
same bearer token when following it. Failed responses contain only a stable safe
error code/message. Unknown jobs and jobs belonging to another client both
return `404`.

```bash
curl -H "Authorization: Bearer $TOKEN" \
  https://api.example.com/api/v1/subtitle-jobs/sub_example
```

Example completed payload:

```json
{
  "job_id": "sub_example",
  "status": "completed",
  "stage": "completed",
  "download_url": "/api/v1/subtitle-jobs/sub_example/download",
  "expires_at": null
}
```

`GET /api/v1/subtitle-jobs/{job_id}/download` reads the recorded SRT object from
internal MinIO and streams it as an `application/x-subrip` attachment. It uses
the same bearer authentication, client ownership scope, job-ID constraint, and
API rate limiter as status polling.

```bash
curl --fail-with-body \
  -H "Authorization: Bearer $TOKEN" \
  -o subtitle.srt \
  https://api.example.com/api/v1/subtitle-jobs/sub_example/download
```

Download responses are `401` for missing/invalid/revoked authentication, `404`
for invalid/unknown/unowned jobs or missing output, `409` while a job is not
successfully completed, and generic `503` when internal storage cannot be read.
Errors do not include object keys, credentials, endpoints, or internal exception
messages.

## Processing and retention

The worker skips video extraction and reuses the existing 120-second FFmpeg
chunking, Whisper-1 transcription, timestamp normalization, contextual English
translation, and SRT generator. Thus the current pipeline still produces English
SRT; the submitted `language` is retained as request metadata while Whisper
auto-detection remains unchanged.

Successful input is deleted after verified result upload. Failed input is
retained for `SUBTITLE_FAILED_RETENTION_DAYS`; output is retained for
`SUBTITLE_OUTPUT_RETENTION_DAYS`. The daily scheduler removes expired objects.
Database job records remain as an audit/status record. The authenticated download
URL is not time-limited, but access continues to require a valid, non-revoked
client token and a matching job owner.

Never use paid-provider calls in automated tests; fake HTTP/storage there.

## Trusted HTTPS prerequisite

The currently known public origin, `http://103.150.116.194:52346`, is not a
trusted HTTPS endpoint. Do not send production bearer tokens over that public
HTTP origin. The authenticated download feature works independently of TLS, but
production mobile use requires all of the following before release:

1. A DNS name controlled by the operator.
2. A publicly trusted certificate for that name (for example, ACME/Let's
   Encrypt), terminated at Nginx or another trusted reverse proxy in front of
   Laravel.
3. HTTPS forwarding to the existing Laravel Nginx service; MinIO ports 9000 and
   9001 must remain private.
4. Laravel `APP_URL` set to the real HTTPS API origin and proxy headers/trusted
   proxies configured so generated route URLs retain `https`.
5. Off-network verification from the Android trust stack without disabling TLS
   verification or installing an untrusted production certificate.

## Deployment checklist

Changed backend files are `routes/api.php`,
`app/Http/Controllers/Api/SubtitleJobController.php`,
`tests/Feature/SubtitleJobApiTest.php`, and this document. The obsolete
`tests/Unit/MinioPresigningConfigurationTest.php` is removed. Design and plan
records live under `docs/superpowers/`.

1. Back up/review the release diff and run `./vendor/bin/pint --dirty`,
   `php artisan route:list --path=api/v1 -v`, and `php artisan test` in the
   release artifact.
2. Deploy only the changed application files. There is no migration or database
   schema action.
3. If production caches routes/config, run `php artisan optimize:clear` followed
   by the deployment's normal `php artisan config:cache` and
   `php artisan route:cache`. If no caches are used, no refresh is required.
4. Do not restart MySQL, Redis, MinIO, or queue workers for this controller/route
   change. Reload/restart PHP-FPM or replace the app container only as required
   by the normal code deployment mechanism.
5. Read-only check that job `sub_ccghxigijc8g1xckvxvp` still exists, belongs to
   the expected API client, is `completed`, and has non-empty `output_disk` and
   `output_path` values. Do not change or hardcode this ID in application code.
6. From inside the application network, use a read-only Laravel/Tinker check of
   `Storage::disk($job->output_disk)->exists($job->output_path)` and confirm it
   returns `true`; do not print credentials or object content.
7. After trusted HTTPS is live, use the owning client token in an Authorization
   header to download the route from a secure external client. Confirm status
   `200`, attachment headers, and valid numbered SRT cues/timestamps/text.
8. Repeat with a different valid client token and confirm `404`. Confirm missing,
   invalid, and revoked tokens return `401`.
9. Inspect application logs for controlled storage errors without secrets. Do
   not expose MinIO or create a new transcription job for this verification.

## Android compatibility

- Phase 4 polling remains compatible: `job_id`, `status`, `stage`,
  `download_url`, and `expires_at` remain present; only the completed URL target
  changes and expiry becomes `null`.
- Phase 5 must resolve the returned `download_url` (or the same path against the
  configured API base URL) and perform a GET with `Authorization: Bearer ...`.
  It must not treat the URL as public or append a token to the query string.
- Phase 5 integration remains pending, as does trusted HTTPS infrastructure and
  a real authenticated download check against MinIO. Until both are verified,
  this feature is implemented and testable but not production-ready.
