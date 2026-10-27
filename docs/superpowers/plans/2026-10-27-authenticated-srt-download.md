# Authenticated SRT Download Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a client-owned Laravel SRT streaming endpoint and replace completed-job presigned URLs with authenticated API download URLs.

**Architecture:** Extend the existing API controller and route group. Resolve the persisted output disk/key only after a client-scoped lookup, pre-open a readable stream before sending success headers, stream fixed-size chunks with guaranteed closure, and keep status polling backward-compatible.

**Tech Stack:** Laravel 12, PHP 8.2, Flysystem/S3, PHPUnit feature tests

**Spec:** `docs/superpowers/specs/2026-10-27-authenticated-srt-download-design.md`

## Global Constraints

- Keep MinIO private; never redirect to MinIO/S3 or expose credentials, object paths, or internal exceptions.
- Preserve existing web/mobile upload, polling, queues, transcription, and storage behavior.
- No schema, dependency, Docker-network, or mobile changes.
- Do not claim HTTPS or production readiness; the current public origin is HTTP-only until separately configured.
- Do not commit, push, deploy, download the private verification object, or trigger paid processing.

## Review Focus

- A crafted original filename containing path separators, quotes, or CR/LF must yield a safe `.srt` attachment filename.
- A storage exception before streaming must return generic `503` JSON and never reveal a bucket key or internal endpoint.
- A job owned by another API client must remain indistinguishable from an unknown job (`404`).
- A response callback must stream incrementally and close its source even if output copying fails.
- A completed status URL must retain the real request/configured HTTP scheme and contain neither credentials nor AWS/MinIO hosts.

---

### Task 1: Authenticated download route and lifecycle guards

**Files:**
- Modify: `tests/Feature/SubtitleJobApiTest.php`
- Modify: `routes/api.php`
- Modify: `app/Http/Controllers/Api/SubtitleJobController.php`

**Interfaces:**
- Consumes: request attribute `api_client`, `SubtitleJob.output_disk`, and `SubtitleJob.output_path`.
- Produces: named route `api.subtitle-jobs.download` and `SubtitleJobController::download(Request $request, string $jobId): Response`.

- [ ] **Step 1: Write failing feature tests** for route regex, missing/invalid/revoked authentication, unknown/cross-client jobs, queued/processing/failed conflict responses, missing output/object, successful streamed content/required headers, safe filename, storage-open failure, resource closure, and absence of redirects/internal path leakage.
- [ ] **Step 2: Run** `php artisan test --filter=SubtitleJobApiTest` and confirm failures are caused by the missing download route/implementation.
- [ ] **Step 3: Implement** the route and minimal controller helpers for scoped lookup, safe filename derivation, pre-opened storage stream, fixed-size output copy, generic failure responses/reporting, and guaranteed source closure.
- [ ] **Step 4: Run** `php artisan test --filter=SubtitleJobApiTest` and confirm all feature tests pass.

### Task 2: Completed status contract

**Files:**
- Modify: `tests/Feature/SubtitleJobApiTest.php`
- Modify: `app/Http/Controllers/Api/SubtitleJobController.php`
- Delete: `tests/Unit/MinioPresigningConfigurationTest.php`

**Interfaces:**
- Consumes: named route `api.subtitle-jobs.download` from Task 1.
- Produces: completed status `download_url` targeting Laravel and `expires_at: null`.

- [ ] **Step 1: Replace the presigning test with failing status assertions** for the authenticated route, null expiry, original HTTP scheme, and absence of AWS/MinIO/presign query data.
- [ ] **Step 2: Run** `php artisan test --filter=SubtitleJobApiTest` and confirm the old `temporaryUrl()` branch fails the new contract.
- [ ] **Step 3: Replace presigning in `show()`** with `route('api.subtitle-jobs.download', ['jobId' => $job->job_id])` while preserving response fields and failed-state semantics.
- [ ] **Step 4: Run** `php artisan test --filter=SubtitleJobApiTest` and confirm the status tests pass.

### Task 3: API operations documentation and regression verification

**Files:**
- Modify: `docs/audio-subtitle-api.md`

**Interfaces:**
- Consumes: endpoint/error/status contracts from Tasks 1-2.
- Produces: deployment checklist, HTTPS prerequisites, manual verification steps for `sub_ccghxigijc8g1xckvxvp`, and Android compatibility notes.

- [ ] **Step 1: Update documentation** to remove public-MinIO/presigning guidance and document bearer-authenticated SRT download, errors, safe transport, deployment/cache actions, and manual checks without fetching private data.
- [ ] **Step 2: Run formatter** `./vendor/bin/pint --dirty`.
- [ ] **Step 3: Run route validation** `php artisan route:list --path=api/v1 -v` and confirm `GET|HEAD api/v1/subtitle-jobs/{jobId}/download` has `api`, `api.client`, and `throttle:subtitle-api`.
- [ ] **Step 4: Run focused and full suites** with `php artisan test --filter=SubtitleJobApiTest` and `php artisan test`; report any pre-existing failures by name.

