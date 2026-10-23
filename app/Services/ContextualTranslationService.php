<?php

namespace App\Services;

use App\Models\Subtitle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ContextualTranslationService
{
    public function translate(int $videoId, array $sourceSegments): Subtitle
    {
        return Cache::lock("subtitle-translation:{$videoId}", 3700)->block(
            600,
            fn (): Subtitle => $this->translateWithCheckpoint($videoId, $sourceSegments)
        );
    }

    private function translateWithCheckpoint(int $videoId, array $sourceSegments): Subtitle
    {
        if ($sourceSegments === []) {
            throw new RuntimeException('Cannot translate an empty transcript.');
        }

        $sourceHash = hash('sha256', json_encode($sourceSegments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $subtitle = Subtitle::firstOrNew([
            'video_id' => $videoId,
            'chunk_index' => -1,
            'type' => 'translated',
        ]);
        $subtitle->language = 'en';

        if ($subtitle->source_hash === $sourceHash && $subtitle->status === 'transcribed') {
            return $subtitle;
        }

        if ($subtitle->source_hash !== $sourceHash) {
            $subtitle->fill(['raw_transcript' => [], 'source_hash' => $sourceHash, 'status' => 'pending']);
            $subtitle->save();
        }

        $completed = collect($subtitle->raw_transcript ?? [])->keyBy('index');
        $batchSize = max(1, (int) config('services.openai.translation_batch_size', 24));
        $contextSize = max(0, (int) config('services.openai.translation_context_size', 3));

        foreach (array_chunk($sourceSegments, $batchSize, true) as $batchOffset => $batch) {
            $requestedIndexes = array_map(fn (array $segment): int => (int) $segment['index'], $batch);
            if (collect($requestedIndexes)->every(fn (int $index): bool => $completed->has($index))) {
                continue;
            }

            $firstPosition = $batchOffset * $batchSize;
            $context = array_slice(
                $sourceSegments,
                max(0, $firstPosition - $contextSize),
                count($batch) + ($contextSize * 2)
            );
            $translations = $this->requestTranslation($batch, $context);

            foreach ($batch as $segment) {
                $index = (int) $segment['index'];
                $completed->put($index, [
                    'index' => $index,
                    'start' => (float) $segment['start'],
                    'end' => (float) $segment['end'],
                    'text' => $translations[$index],
                ]);
            }

            // Durable checkpoint: a job retry skips every batch already paid for.
            $subtitle->update([
                'raw_transcript' => $completed->sortKeys()->values()->all(),
                'source_hash' => $sourceHash,
                'status' => 'pending',
            ]);
        }

        $subtitle->update(['status' => 'transcribed']);

        return $subtitle->fresh();
    }

    private function requestTranslation(array $batch, array $context): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key not configured. Set OPENAI_API_KEY in .env.');
        }

        $requested = array_map(fn (array $item): array => [
            'index' => (int) $item['index'],
            'text' => (string) $item['text'],
        ], $batch);
        $contextLines = array_map(fn (array $item): array => [
            'index' => (int) $item['index'],
            'text' => (string) $item['text'],
        ], $context);

        $response = Http::withToken($apiKey)
            ->timeout((int) config('services.openai.timeout', 600))
            ->post(rtrim((string) config('services.openai.endpoint'), '/').'/responses', [
                'model' => (string) config('services.openai.translation_model', 'gpt-5.6-luna'),
                'reasoning' => ['effort' => 'none'],
                'instructions' => 'Translate subtitle cues into natural, context-aware English. Input may contain Mandarin Chinese, Japanese, Korean, Thai, or mixed speech. Preserve meaning, names, tone, and continuity. Do not summarize, explain, merge, or add information. Return exactly one translation for every requested index.',
                'input' => json_encode([
                    'context' => $contextLines,
                    'translate' => $requested,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'max_output_tokens' => max(256, count($batch) * 64),
                'text' => ['format' => [
                    'type' => 'json_schema',
                    'name' => 'subtitle_translation',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'translations' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'index' => ['type' => 'integer'],
                                        'text' => ['type' => 'string'],
                                    ],
                                    'required' => ['index', 'text'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['translations'],
                        'additionalProperties' => false,
                    ],
                ]],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: $response->body();
            throw new RuntimeException("OpenAI translation error ({$response->status()}): {$message}");
        }

        $output = collect($response->json('output', []))
            ->flatMap(fn (array $output): array => $output['content'] ?? [])
            ->firstWhere('type', 'output_text');
        $text = $output['text'] ?? null;
        $decoded = is_string($text) ? json_decode($text, true) : null;
        $translations = collect($decoded['translations'] ?? [])->mapWithKeys(
            fn (array $item): array => [(int) ($item['index'] ?? -1) => trim((string) ($item['text'] ?? ''))]
        );

        $expected = collect($batch)->pluck('index')->map(fn ($index): int => (int) $index)->sort()->values();
        if ($translations->keys()->sort()->values()->all() !== $expected->all() || $translations->contains('')) {
            throw new RuntimeException('OpenAI returned an incomplete or mismatched subtitle translation.');
        }

        return $translations->all();
    }
}
