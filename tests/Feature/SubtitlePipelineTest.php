<?php

namespace Tests\Feature;

use App\Jobs\CleanupVideoProcessingJob;
use App\Jobs\MergeSubtitleJob;
use App\Jobs\TranscribeChunkJob;
use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\Video;
use App\Services\MergeSubtitleService;
use App\Services\SrtGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubtitlePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_multilingual_audio_becomes_downloadable_english_srt(): void
    {
        Storage::fake('minio');
        Storage::fake('local');
        Queue::fake();
        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.endpoint' => 'https://api.openai.test/v1',
        ]);

        Http::fake([
            'https://api.openai.test/v1/audio/translations' => Http::response([
                'language' => 'zh',
                'text' => 'Welcome. Thank you.',
                'segments' => [
                    ['start' => 0.25, 'end' => 1.80, 'text' => 'Welcome.'],
                    ['start' => 2.10, 'end' => 3.40, 'text' => 'Thank you.'],
                ],
            ]),
        ]);

        $video = Video::create([
            'upload_id' => 'b8e6813b-588c-43bd-a789-bf8cc55ad135',
            'filename' => 'mandarin.mp4',
            'original_name' => 'mandarin.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/demo/original.mp4',
        ]);
        $chunk = AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,
            'path' => 'videos/demo/chunks/chunk_0.flac',
            'status' => 'pending',
        ]);
        Storage::disk('minio')->put($chunk->path, 'fake-flac');

        (new TranscribeChunkJob($chunk))->handle();
        (new MergeSubtitleJob($video))->handle(
            app(MergeSubtitleService::class),
            app(SrtGeneratorService::class),
        );

        $video->refresh();
        $subtitle = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('language', 'en')
            ->where('type', 'original')
            ->firstOrFail();

        $this->assertSame('done', $video->status);
        $this->assertSame('videos/'.$video->id.'/subtitles/en.srt', $subtitle->path);
        $this->assertSame(
            "1\n00:00:00,250 --> 00:00:01,800\nWelcome.\n\n2\n00:00:02,100 --> 00:00:03,400\nThank you.\n",
            Storage::disk('minio')->get($subtitle->path),
        );
        Queue::assertPushed(CleanupVideoProcessingJob::class);

        $this->get(route('upload.download', ['uploadId' => $video->upload_id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/x-subrip');
    }
}
