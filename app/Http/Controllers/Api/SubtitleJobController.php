<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubtitleJobRequest;
use App\Jobs\ProcessAudioSubtitleJob;
use App\Models\SubtitleJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
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

        $downloadUrl = null;
        if ($job->status === 'completed' && $job->output_path) {
            $downloadUrl = route('api.subtitle-jobs.download', ['jobId' => $job->job_id], false);
        }

        $response = [
            'job_id' => $job->job_id,
            'status' => $job->status,
            'stage' => $job->stage,
            'download_url' => $downloadUrl,
            'expires_at' => null,
        ];
        if ($job->status === 'failed') {
            $response['error'] = ['code' => $job->error_code ?: 'PROCESSING_FAILED', 'message' => 'Subtitle generation could not be completed.'];
        }

        return response()->json($response);
    }

    public function download(Request $request, string $jobId): Response
    {
        $job = SubtitleJob::where('job_id', $jobId)
            ->where('api_client_id', $request->attributes->get('api_client')->id)
            ->first();

        if (! $job) {
            return response()->json(['message' => 'The subtitle job was not found.'], 404);
        }

        if ($job->status !== 'completed') {
            return response()->json([
                'message' => $job->status === 'failed'
                    ? 'The subtitle job did not complete successfully.'
                    : 'The subtitle file is not ready for download.',
            ], 409);
        }

        if (! $job->output_path) {
            return response()->json(['message' => 'The subtitle file was not found.'], 404);
        }

        $diskName = $job->output_disk ?: (string) config('subtitle_api.output_disk');

        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($job->output_path)) {
                return response()->json(['message' => 'The subtitle file was not found.'], 404);
            }

            $stream = $disk->readStream($job->output_path);
            if (! is_resource($stream)) {
                throw new \RuntimeException('Subtitle output could not be opened as a stream.');
            }
        } catch (Throwable $e) {
            Log::warning('Subtitle download storage read failed.', [
                'job_id' => $job->job_id,
                'exception' => $e::class,
            ]);

            return response()->json(['message' => 'The subtitle file is temporarily unavailable.'], 503);
        }

        return response()->streamDownload(function () use ($stream, $job): void {
            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        throw new \RuntimeException('Subtitle stream could not be read.');
                    }

                    echo $chunk;
                }
            } catch (Throwable $e) {
                Log::warning('Subtitle download stream interrupted.', [
                    'job_id' => $job->job_id,
                    'exception' => $e::class,
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, $this->downloadFilename($job->input_filename), [
            'Content-Type' => 'application/x-subrip; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function downloadFilename(?string $inputFilename): string
    {
        $basename = basename(str_replace('\\', '/', (string) $inputFilename));
        $stem = pathinfo($basename, PATHINFO_FILENAME);
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem) ?: '';
        $stem = trim(preg_replace('/-+/', '-', $stem) ?: '', '.-_');

        return ($stem !== '' ? $stem : 'subtitle').'.srt';
    }
}
