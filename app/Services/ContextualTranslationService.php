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
        $batchSize = max(1, (int) config('services.openai.translation_batch_size', 8));
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
            $translations = $this->requestTranslationWithAdaptiveSplit($batch, $context);

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

    private function requestTranslationWithAdaptiveSplit(array $batch, array $context): array
    {
        try {
            return $this->requestTranslation($batch, $context);
        } catch (RuntimeException $exception) {
            if (count($batch) === 1
                || ! str_contains($exception->getMessage(), 'incomplete translation response (max_output_tokens)')) {
                throw $exception;
            }

            $middle = (int) ceil(count($batch) / 2);
            $left = array_slice($batch, 0, $middle, true);
            $right = array_slice($batch, $middle, null, true);

            return $this->requestTranslationWithAdaptiveSplit($left, $context)
                + $this->requestTranslationWithAdaptiveSplit($right, $context);
        }
    }

    private function requestTranslation(array $batch, array $context): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key not configured. Set OPENAI_API_KEY in .env.');
        }

        $requested = array_map(fn (array $item): string => (string) $item['text'], $batch);
        $contextLines = array_map(fn (array $item): array => [
            'index' => (int) $item['index'],
            'text' => (string) $item['text'],
        ], $context);

        $response = Http::withToken($apiKey)
            ->timeout((int) config('services.openai.timeout', 600))
            ->post(rtrim((string) config('services.openai.endpoint'), '/').'/responses', [
                'model' => (string) config('services.openai.translation_model', 'gpt-4.1-mini'),
                'instructions' => 'Translate subtitle cues into natural, context-aware English. Input may contain Mandarin Chinese, Japanese, Korean, Thai, or mixed speech. Preserve meaning, names, tone, and continuity. Do not summarize, explain, merge, or add information. Return exactly one non-empty translation for each input cue, in the identical order.',
                'input' => json_encode([
                    'context' => $contextLines,
                    'translate' => $requested,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                // This is only a ceiling; billing follows actual generated tokens.
                // Size it from source length so dialogue-heavy batches are not truncated.
                'max_output_tokens' => max(
                    4096,
                    min(12000, (int) ceil(mb_strlen(implode(' ', $requested))) + (count($batch) * 64))
                ),
                'text' => ['format' => [
                    'type' => 'json_schema',
                    'name' => 'subtitle_translation',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'translations' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => count($batch),
                                'maxItems' => count($batch),
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

        if ($response->json('status') === 'incomplete') {
            $reason = $response->json('incomplete_details.reason', 'unknown');
            throw new RuntimeException("OpenAI returned an incomplete translation response ({$reason}).");
        }

        $output = collect($response->json('output', []))
            ->flatMap(fn (array $output): array => $output['content'] ?? [])
            ->firstWhere('type', 'output_text');
        $text = $output['text'] ?? null;
        $decoded = is_string($text) ? json_decode($text, true) : null;
        $orderedTranslations = $decoded['translations'] ?? [];
        if (! is_array($orderedTranslations) || count($orderedTranslations) !== count($batch)) {
            throw new RuntimeException('OpenAI returned an incomplete or mismatched subtitle translation.');
        }

        $translations = [];
        foreach (array_values($batch) as $position => $segment) {
            $text = trim((string) $orderedTranslations[$position]);
            $translations[(int) $segment['index']] = $text !== '' ? $text : '[inaudible]';
        }

        return $translations;
    }
}
