# Authenticated SRT Download Design

## Intent

Serve completed SRT files through the authenticated Laravel API so mobile clients no longer receive unusable AWS/MinIO presigned URLs. Keep MinIO private and preserve the existing per-client ownership boundary and status response shape.

## Goals

- Add `GET /api/v1/subtitle-jobs/{jobId}/download` under the existing API-client and rate-limit middleware.
- Scope every lookup by both `job_id` and authenticated `api_client_id`.
- Stream the recorded SRT object from the existing internal storage disk with bounded memory and safe attachment headers.
- Keep `download_url` in completed status responses, point it to Laravel, and return `expires_at: null`.
- Return controlled JSON failures before a successful stream begins and log storage failures without returning internal details.
- Document HTTPS prerequisites, bearer-authenticated download behavior, deployment, and manual verification.

## Non-goals and anti-goals

- No schema, transcription, audio processing, queue, Docker-network, MinIO exposure, mobile-code, or dependency changes.
- No public or unauthenticated download, token-bearing URL, MinIO redirect, S3 presigning, paid transcription, automatic deployment, or private-object download during implementation.
- Do not claim production readiness or trusted HTTPS until infrastructure and a real authenticated download are verified separately.

## Constraints

- Laravel 12/PHP 8.2 and the current `api.client` plus `throttle:subtitle-api` middleware remain authoritative.
- The stored object key comes from `subtitle_jobs.output_path`; the disk comes from `output_disk`, with the configured output disk as compatibility fallback.
- The public deployment currently has only `http://103.150.116.194:52346`; documentation must not represent it as HTTPS or recommend bearer tokens over public HTTP for production.
- The status response must remain compatible with existing polling clients.

## Approaches considered

1. **Laravel proxy streaming (selected).** Open a read stream from the internal disk and copy it to the response output. This retains authentication and ownership checks, keeps MinIO private, and uses bounded memory. It adds Laravel/Nginx bandwidth load, which is acceptable for SRT files.
2. **Public MinIO presigned URLs.** Rejected because no trusted public MinIO endpoint exists and exposing MinIO is explicitly out of scope.
3. **Buffer the object in Laravel.** Rejected because `Storage::get()` loads the full file and violates the streaming requirement.

## Design

Register the download route before the generic status route and apply the same job-ID regex. `SubtitleJobController::download()` performs the same two-column scoped lookup used by `show()`. It returns `409` for queued/processing and failed jobs, with stable generic JSON messages; absent jobs/outputs return `404` without ownership or object-key detail.

For a completed job, resolve the recorded disk/key, verify existence, then obtain `readStream()` before creating the download response. Failures are reported server-side and become a generic `503` JSON response. The streamed callback copies fixed-size chunks to output, always closes the source in `finally`, and reports unexpected mid-stream failures without exposing exception text. The attachment name is derived from the original input basename, stripped of path/header/control hazards, normalized to a conservative filename, and forced to `.srt`; `subtitle.srt` is the fallback.

Completed status responses use Laravel's named route to produce a relative API path in the existing `download_url` field. This avoids advertising the currently insecure HTTP origin and lets clients resolve it against their configured API base URL. `expires_at` remains present and becomes `null`; clients must still send the bearer header when following the URL.

## Error behavior

- Missing, invalid, or revoked token: existing middleware returns `401` JSON.
- Invalid route pattern, unknown job, or cross-client job: `404`.
- Queued/processing or failed job: `409` with a stable generic JSON message.
- Completed job without a usable output reference or missing object: `404`.
- Storage metadata/open failure: `503` with a generic JSON message and server-side reporting.
- Failure after headers begin: log server-side, close the stream, and terminate the stream; HTTP status cannot safely be changed at that stage.

## Testing

Feature tests use isolated database rows and fake or mocked storage only. They cover authentication/revocation, route constraints, ownership, lifecycle states, missing output/object, storage failure, content/headers/streaming/closure, filename sanitization, lack of leaked paths/redirects, and the completed status contract. Existing feature and full suites guard web/API regressions without invoking Whisper.

