<?php

namespace App\Jobs;

use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\SubtitleJob;
use App\Models\Video;
use App\Services\AudioChunkService;
use App\Services\ContextualTranslationService;
use App\Services\MergeSubtitleService;
use App\Services\SrtGeneratorService;
use App\Services\WhisperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessAudioSubtitleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public array $backoff;

    public int $maxExceptions;

    public function __construct(public readonly int $subtitleJobId)
    {
        $this->tries = (int) config('subtitle_api.tries');
        $this->timeout = (int) config('subtitle_api.timeout');
        $this->backoff = config('subtitle_api.backoff');
        $this->maxExceptions = (int) config('subtitle_api.max_exceptions');
        $this->onConnection('audio_subtitles');
        $this->onQueue((string) config('queue.connections.audio_subtitles.queue'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("subtitle-job:{$this->subtitleJobId}"))
            ->releaseAfter((int) config('subtitle_api.lock_release_seconds'))
            ->expireAfter((int) config('subtitle_api.lock_seconds'))];
    }

    public function handle(AudioChunkService $chunker, WhisperService $whisper, MergeSubtitleService $merger, ContextualTranslationService $translator, SrtGeneratorService $srt): void
    {
        $job = SubtitleJob::findOrFail($this->subtitleJobId);
        if ($job->status === 'completed') {
            return;
        }

        $job->update(['status' => 'processing', 'stage' => 'preparing', 'processing_started_at' => $job->processing_started_at ?: now(), 'error_code' => null, 'error_message' => null]);

        $video = $job->video_id ? Video::findOrFail($job->video_id) : Video::create([
            'upload_id' => $job->job_id,
            'filename' => $job->input_filename,
            'original_name' => $job->input_filename,
            'total_chunks' => 0,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => $job->input_path,
        ]);
        $job->update(['video_id' => $video->id]);

        $chunks = $this->completeExistingChunks($video);
        if ($chunks === null) {
            $chunks = $chunker->split($video, $job->input_path);
        }
        $job->update(['stage' => 'transcribing']);
        foreach ($chunks as $chunk) {
            if ($chunk->status === 'transcribed') {
                continue;
            }
            $result = $whisper->transcribe($chunk->path);
            $segments = $result['segments'] ?? $result;
            Subtitle::updateOrCreate(
                ['video_id' => $video->id, 'chunk_index' => $chunk->chunk_index, 'type' => 'original'],
                ['language' => 'source', 'raw_transcript' => $segments, 'status' => 'transcribed']
            );
            $chunk->markAsTranscribed();
        }

        $job->update(['stage' => 'generating_subtitles']);
        $source = $merger->merge($video->id, 'source', 'original');
        $translator->translate($video->id, $source->raw_transcript ?? []);
        $generatedPath = $srt->generate($video->id, 'en', 'translated');

        $job->update(['stage' => 'uploading_result']);
        $outputDisk = (string) config('subtitle_api.output_disk');
        $outputPath = "subtitle-jobs/{$job->job_id}/result.srt";
        $contents = Storage::disk('minio')->get($generatedPath);
        Storage::disk($outputDisk)->put($outputPath, $contents, ['visibility' => 'private', 'ContentType' => 'application/x-subrip']);
        if (! Storage::disk($outputDisk)->exists($outputPath)) {
            throw new \RuntimeException('Result upload could not be verified.');
        }

        $video->update(['status' => 'done']);
        $job->update(['status' => 'completed', 'stage' => 'completed', 'output_disk' => $outputDisk, 'output_path' => $outputPath, 'completed_at' => now()]);
        Storage::disk($job->input_disk)->delete($job->input_path);
    }

    public function failed(Throwable $e): void
    {
        SubtitleJob::whereKey($this->subtitleJobId)->where('status', '!=', 'completed')->update([
            'status' => 'failed', 'error_code' => 'TRANSCRIPTION_FAILED', 'error_message' => mb_substr($e->getMessage(), 0, 2000), 'failed_at' => now(),
        ]);
    }

    /** @return array<int, AudioChunk>|null */
    private function completeExistingChunks(Video $video): ?array
    {
        if ($video->total_chunks < 1) {
            return null;
        }

        $chunks = AudioChunk::where('video_id', $video->id)->orderBy('chunk_index')->get();
        if ($chunks->count() !== $video->total_chunks) {
            return null;
        }

        foreach ($chunks as $index => $chunk) {
            if ($chunk->chunk_index !== $index || ! Storage::disk('minio')->exists($chunk->path)) {
                return null;
            }
        }

        return $chunks->all();
    }
}
