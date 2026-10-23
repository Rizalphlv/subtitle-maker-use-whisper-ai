<?php

namespace Tests\Unit;

use App\Services\WhisperService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhisperServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.endpoint' => 'https://api.openai.test/v1',
            'services.openai.model' => 'whisper-1',
            'services.openai.whisper_prompt' => null,
        ]);
    }

    public function test_transcribe_translates_to_timestamped_english_segments(): void
    {
        Storage::fake('minio');
        Storage::fake('local');
        Storage::disk('minio')->put('chunks/demo.mp3', 'fake-audio');

        Http::fake([
            'https://api.openai.test/v1/audio/translations' => Http::response([
                'language' => 'ja',
                'text' => 'Hello world',
                'segments' => [
                    ['text' => 'Hello world', 'start' => 0.1, 'end' => 1.6],
                    ['text' => '[Music]', 'start' => 1.6, 'end' => 2.0],
                ],
            ], 200),
        ]);

        $service = new WhisperService;
        $result = $service->transcribe('chunks/demo.mp3');

        $this->assertSame('en', $result['language']);
        $this->assertCount(1, $result['segments']);
        $this->assertSame('Hello world', $result['segments'][0]['text']);
        $this->assertSame(0.1, $result['segments'][0]['start']);
        $this->assertSame(1.6, $result['segments'][0]['end']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.test/v1/audio/translations'
            && str_contains($request->body(), 'name="model"')
            && str_contains($request->body(), 'whisper-1')
            && str_contains($request->body(), 'verbose_json')
        );
    }

    public function test_filters_prompt_leakage_and_repeated_silence_hallucinations(): void
    {
        Storage::fake('minio');
        Storage::fake('local');
        Storage::disk('minio')->put('chunks/silence.flac', 'fake-audio');
        config(['services.openai.whisper_prompt' => 'Bartholomew, Tanrak']);

        Http::fake([
            'https://api.openai.test/v1/audio/translations' => Http::response([
                'text' => 'Real dialogue. Bartholomew, Tanrak. Thank you. Thank you. Thank you.',
                'segments' => [
                    ['text' => 'Real dialogue.', 'start' => 0, 'end' => 2],
                    ['text' => 'Bartholomew, Tanrak.', 'start' => 2, 'end' => 4],
                    ['text' => 'Thank you.', 'start' => 4, 'end' => 6],
                    ['text' => 'Thank you.', 'start' => 6, 'end' => 8],
                    ['text' => 'Thank you.', 'start' => 8, 'end' => 10],
                ],
            ]),
        ]);

        $result = (new WhisperService)->transcribe('chunks/silence.flac');

        $this->assertSame([
            ['start' => 0.0, 'end' => 2.0, 'text' => 'Real dialogue.'],
        ], $result['segments']);
    }
}
