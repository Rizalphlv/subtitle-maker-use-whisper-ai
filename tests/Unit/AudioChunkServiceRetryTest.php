<?php

namespace Tests\Unit;

use App\Models\AudioChunk;
use App\Models\Video;
use App\Services\AudioChunkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class AudioChunkServiceRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_chunk_rows_are_completed_without_duplicates(): void
    {
        Storage::fake('local');
        Storage::fake('minio');
        $video = $this->video();
        AudioChunk::create(['video_id' => $video->id, 'chunk_index' => 0, 'start_time' => 0, 'path' => 'old.flac', 'status' => 'pending']);
        Storage::disk('local')->put('retry/chunks/chunk_000.flac', 'zero');
        Storage::disk('local')->put('retry/chunks/chunk_001.flac', 'one');

        $chunks = $this->invokePersistence($video, 2);

        $this->assertCount(2, $chunks);
        $this->assertSame(2, AudioChunk::where('video_id', $video->id)->count());
        $this->assertSame(2, $video->fresh()->total_chunks);
        $this->assertSame([0, 120], AudioChunk::where('video_id', $video->id)->orderBy('chunk_index')->pluck('start_time')->all());
    }

    public function test_retry_preserves_completed_transcription_state(): void
    {
        Storage::fake('local');
        Storage::fake('minio');
        $video = $this->video();
        AudioChunk::create(['video_id' => $video->id, 'chunk_index' => 0, 'start_time' => 0, 'path' => 'old.flac', 'status' => 'transcribed']);
        Storage::disk('local')->put('retry/chunks/chunk_000.flac', 'zero');

        $this->invokePersistence($video, 1);

        $this->assertSame('transcribed', AudioChunk::where('video_id', $video->id)->firstOrFail()->status);
        $this->assertSame(1, AudioChunk::where('video_id', $video->id)->count());
    }

    private function invokePersistence(Video $video, int $count): array
    {
        $method = new ReflectionMethod(AudioChunkService::class, 'uploadChunksAndCreateRecords');

        return $method->invoke(new AudioChunkService, $video, 'retry/chunks', $count);
    }

    private function video(): Video
    {
        return Video::create([
            'upload_id' => 'retry-'.uniqid(), 'filename' => 'input.mp3', 'original_name' => 'input.mp3',
            'total_chunks' => 0, 'status' => 'processing', 'target_language' => 'en', 'minio_path' => 'input.mp3',
        ]);
    }
}
