<?php

namespace Tests\Unit;

use App\Jobs\ProcessAudioSubtitleJob;
use App\Models\ApiClient;
use App\Models\AudioChunk;
use App\Models\SubtitleJob;
use App\Models\Video;
use App\Services\AudioChunkService;
use App\Services\ContextualTranslationService;
use App\Services\MergeSubtitleService;
use App\Services\SrtGeneratorService;
use App\Services\WhisperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProcessAudioSubtitleJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_execution_does_not_touch_completed_output(): void
    {
        $record = $this->record('completed');
        $chunker = $this->createMock(AudioChunkService::class);
        $chunker->expects($this->never())->method('split');

        (new ProcessAudioSubtitleJob($record->id))->handle(
            $chunker,
            $this->createMock(WhisperService::class),
            $this->createMock(MergeSubtitleService::class),
            $this->createMock(ContextualTranslationService::class),
            $this->createMock(SrtGeneratorService::class),
        );

        $this->assertSame('completed', $record->fresh()->status);
    }

    public function test_final_failure_is_persisted_without_exposing_exception_via_api_field(): void
    {
        $record = $this->record('processing');
        (new ProcessAudioSubtitleJob($record->id))->failed(new RuntimeException('provider secret detail'));

        $record->refresh();
        $this->assertSame('failed', $record->status);
        $this->assertSame('TRANSCRIPTION_FAILED', $record->error_code);
        $this->assertSame('provider secret detail', $record->error_message);
        $this->assertNotNull($record->failed_at);
    }

    public function test_queue_timing_and_locking_are_safe_and_isolated(): void
    {
        config([
            'subtitle_api.timeout' => 7200,
            'subtitle_api.lock_seconds' => 7500,
            'subtitle_api.lock_release_seconds' => 900,
            'queue.connections.audio_subtitles.retry_after' => 7800,
        ]);
        $job = new ProcessAudioSubtitleJob(123);
        $middleware = $job->middleware()[0];

        $this->assertSame('audio_subtitles', $job->connection);
        $this->assertSame('audio-subtitles', $job->queue);
        $this->assertGreaterThan($job->timeout, $middleware->expiresAfter);
        $this->assertGreaterThan($middleware->expiresAfter, config('queue.connections.audio_subtitles.retry_after'));
        $this->assertSame(900, $middleware->releaseAfter);
        $this->assertSame(3, $job->maxExceptions);
    }

    public function test_complete_existing_chunks_are_reused_after_interruption(): void
    {
        Storage::fake('minio');
        $record = $this->record('processing');
        $video = Video::create([
            'upload_id' => $record->job_id, 'filename' => 'input.mp3', 'original_name' => 'input.mp3',
            'total_chunks' => 1, 'status' => 'processing', 'target_language' => 'en', 'minio_path' => $record->input_path,
        ]);
        $record->update(['video_id' => $video->id]);
        Storage::disk('minio')->put('chunks/0.flac', 'audio');
        AudioChunk::create(['video_id' => $video->id, 'chunk_index' => 0, 'start_time' => 0, 'path' => 'chunks/0.flac', 'status' => 'transcribed']);

        $chunker = $this->createMock(AudioChunkService::class);
        $chunker->expects($this->never())->method('split');
        $merger = $this->createMock(MergeSubtitleService::class);
        $merger->method('merge')->willThrowException(new RuntimeException('stop after reuse assertion'));

        $this->expectException(RuntimeException::class);
        (new ProcessAudioSubtitleJob($record->id))->handle(
            $chunker, $this->createMock(WhisperService::class), $merger,
            $this->createMock(ContextualTranslationService::class), $this->createMock(SrtGeneratorService::class),
        );
    }

    private function record(string $status): SubtitleJob
    {
        $client = ApiClient::create(['name' => 'test', 'token_hash' => hash('sha256', 'token')]);

        return SubtitleJob::create([
            'api_client_id' => $client->id,
            'job_id' => 'sub_test1234567890',
            'status' => $status,
            'stage' => $status === 'completed' ? 'completed' : 'transcribing',
            'language' => 'auto',
            'output_format' => 'srt',
            'input_disk' => 'minio',
            'input_path' => 'subtitle-jobs/test/input.mp3',
            'input_filename' => 'input.mp3',
            'input_mime_type' => 'audio/mpeg',
            'input_size' => 10,
            'output_disk' => $status === 'completed' ? 'minio' : null,
            'output_path' => $status === 'completed' ? 'subtitle-jobs/test/result.srt' : null,
        ]);
    }
}
