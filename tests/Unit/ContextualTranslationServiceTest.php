<?php

namespace Tests\Unit;

use App\Models\Subtitle;
use App\Models\Video;
use App\Services\ContextualTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContextualTranslationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_translation_is_reused_without_another_api_call(): void
    {
        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.endpoint' => 'https://api.openai.test/v1',
            'services.openai.translation_batch_size' => 24,
        ]);
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'output' => [['content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['translations' => ['Good morning.']]),
                ]]]],
            ]),
        ]);
        $video = Video::create([
            'upload_id' => 'translation-idempotency',
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);
        $segments = [['index' => 1, 'start' => 0.0, 'end' => 1.0, 'text' => 'おはよう']];
        $service = new ContextualTranslationService;

        $first = $service->translate($video->id, $segments);
        $second = $service->translate($video->id, $segments);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Good morning.', Subtitle::findOrFail($first->id)->raw_transcript[0]['text']);
        Http::assertSentCount(1);
    }
}
