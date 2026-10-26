<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubtitleJobRequest;
use App\Jobs\ProcessAudioSubtitleJob;
use App\Models\SubtitleJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SubtitleJobController extends Controller
{
    public function store(StoreSubtitleJobRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $jobId = 'sub_'.Str::lower(Str::random(20));
        $disk = (string) config('subtitle_api.input_disk');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = "subtitle-jobs/{$jobId}/input.{$extension}";

        try {
            $stream = fopen($file->getRealPath(), 'rb');
            $stored = Storage::disk($disk)->writeStream($path, $stream, ['visibility' => 'private']);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! $stored) {
                throw new \RuntimeException('Input storage failed.');
            }

            $job = DB::transaction(function () use ($request, $file, $jobId, $disk, $path) {
                $job = SubtitleJob::create([
                    'api_client_id' => $request->attributes->get('api_client')->id,
                    'job_id' => $jobId,
                    'status' => 'queued',
                    'language' => $request->input('language', 'auto'),
                    'output_format' => 'srt',
                    'input_disk' => $disk,
                    'input_path' => $path,
                    'input_filename' => basename($file->getClientOriginalName()),
                    'input_mime_type' => (string) $file->getMimeType(),
                    'input_size' => (int) $file->getSize(),
                ]);
                ProcessAudioSubtitleJob::dispatch($job->id)->afterCommit();

                return $job;
            });
        } catch (Throwable $e) {
            // An after-commit queue push may fail after the row is durable. In
            // that case retain the input for diagnosis/manual redispatch and
            // expose a terminal, truthful state instead of a stuck queued job.
            $persistedJob = SubtitleJob::where('job_id', $jobId)->first();
            if ($persistedJob) {
                $persistedJob->update([
                    'status' => 'failed',
                    'error_code' => 'DISPATCH_FAILED',
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                    'failed_at' => now(),
                ]);
            } else {
                Storage::disk($disk)->delete($path);
            }
            report($e);

            return response()->json(['message' => 'The subtitle job could not be accepted.'], 503);
        }

        return response()->json([
            'job_id' => $job->job_id,
            'status' => 'queued',
            'poll_interval_seconds' => config('subtitle_api.poll_interval_seconds'),
        ], 202);
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = SubtitleJob::where('job_id', $jobId)
            ->where('api_client_id', $request->attributes->get('api_client')->id)
            ->firstOrFail();

        $expiresAt = null;
        $downloadUrl = null;
        if ($job->status === 'completed' && $job->output_path) {
            $expiresAt = now()->addMinutes((int) config('subtitle_api.signed_url_minutes'));
            $downloadUrl = Storage::disk((string) config('subtitle_api.presign_disk'))->temporaryUrl(
                $job->output_path,
                $expiresAt,
                ['ResponseContentDisposition' => 'attachment; filename="'.pathinfo($job->input_filename, PATHINFO_FILENAME).'.srt"']
            );
        }

        $response = [
            'job_id' => $job->job_id,
            'status' => $job->status,
            'stage' => $job->stage,
            'download_url' => $downloadUrl,
            'expires_at' => $expiresAt?->utc()->toIso8601String(),
        ];
        if ($job->status === 'failed') {
            $response['error'] = ['code' => $job->error_code ?: 'PROCESSING_FAILED', 'message' => 'Subtitle generation could not be completed.'];
        }

        return response()->json($response);
    }
}
