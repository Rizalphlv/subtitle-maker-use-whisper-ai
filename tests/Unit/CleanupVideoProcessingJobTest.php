<?php

namespace Tests\Unit;

use App\Jobs\CleanupVideoProcessingJob;
use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupVideoProcessingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_keeps_final_srt_and_removes_temporary_chunks(): void
    {
        Storage::fake('minio');
        $video = Video::create([
            'upload_id' => 'c8e6813b-588c-43bd-a789-bf8cc55ad135',
            'filename' => 'test.mp4', 'original_name' => 'test.mp4',
            'total_chunks' => 1, 'status' => 'done', 'target_language' => 'en',
            'minio_path' => 'videos/demo/original.mp4',
        ]);
        $chunk = AudioChunk::create([
            'video_id' => $video->id, 'chunk_index' => 0, 'start_time' => 0,
            'path' => 'videos/demo/chunks/chunk_0.flac', 'status' => 'transcribed',
        ]);
        $path = 'videos/'.$video->id.'/subtitles/en.srt';
        Subtitle::create([
            'video_id' => $video->id, 'chunk_index' => -1, 'language' => 'en',
            'raw_transcript' => [['index' => 1, 'start' => 0, 'end' => 1, 'text' => 'Hi']],
            'path' => $path, 'type' => 'original', 'status' => 'transcribed',
        ]);
        Storage::disk('minio')->put($chunk->path, 'audio');
        Storage::disk('minio')->put($path, 'subtitle');

        (new CleanupVideoProcessingJob($video))->handle();

        $this->assertFalse(Storage::disk('minio')->exists($chunk->path));
        $this->assertTrue(Storage::disk('minio')->exists($path));
    }
}
