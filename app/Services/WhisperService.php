<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * WhisperService
 *
 * Translates multilingual audio directly to timestamped English segments.
 */
class WhisperService implements SpeechToTextInterface
{
    public function transcribe(string $audioMinioPath): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key not configured. Set OPENAI_API_KEY in .env.');
        }

        $extension = strtolower(pathinfo($audioMinioPath, PATHINFO_EXTENSION)) ?: 'flac';
        $tempPath = 'temp/whisper/'.uniqid('chunk_', true).'.'.$extension;

        try {
            $stream = Storage::disk('minio')->readStream($audioMinioPath);
            if ($stream === false) {
                throw new RuntimeException("Cannot read audio from storage: {$audioMinioPath}");
            }
            try {
                Storage::disk('local')->writeStream($tempPath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $path = Storage::disk('local')->path($tempPath);
            $audio = file_get_contents($path);
            if ($audio === false) {
                throw new RuntimeException("Cannot read temporary audio file: {$path}");
            }

            $endpoint = rtrim((string) config('services.openai.endpoint'), '/').'/audio/translations';
            $payload = [
                'model' => (string) config('services.openai.model', 'whisper-1'),
                'response_format' => 'verbose_json',
                'temperature' => '0',
            ];
            $prompt = trim((string) config('services.openai.whisper_prompt'));
            if ($prompt !== '') {
                $payload['prompt'] = $prompt;
            }

            $response = Http::withToken($apiKey)
                ->timeout((int) config('services.openai.timeout', 600))
                ->retry(2, 1000, throw: false)
                ->attach('file', $audio, basename($path))
                ->post($endpoint, $payload);

            if ($response->failed()) {
                $message = $response->json('error.message') ?: $response->body();
                throw new RuntimeException("OpenAI Whisper error ({$response->status()}): {$message}");
            }

            $rawSegments = $response->json('segments') ?? [];
            $segments = [];
            foreach ($rawSegments as $segment) {
                $text = trim((string) ($segment['text'] ?? ''));
                $start = (float) ($segment['start'] ?? 0);
                $end = (float) ($segment['end'] ?? $start);
                if ($text !== '' && $end > $start && ! $this->isNoiseOnly($text)) {
                    $segments[] = ['start' => $start, 'end' => $end, 'text' => $text];
                }
            }
            $segments = $this->removeRepeatedHallucinations($segments);

            if ($rawSegments === [] && trim((string) $response->json('text', '')) !== '') {
                throw new RuntimeException('OpenAI Whisper returned text without usable timestamps.');
            }

            return ['segments' => $segments, 'language' => 'en'];
        } catch (Throwable $e) {
            Log::error('WhisperService: translation failed', ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            Storage::disk('local')->delete($tempPath);
        }
    }

    private function isNoiseOnly(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        $prompt = $this->normalizeText((string) config('services.openai.whisper_prompt'));

        return ($prompt !== '' && $normalized === $prompt)
            || preg_match('/^(music|noise|silence|applause|laughter|inaudible|background noise)$/u', $normalized) === 1;
    }

    private function removeRepeatedHallucinations(array $segments): array
    {
        $frequencies = [];
        foreach ($segments as $segment) {
            $normalized = $this->normalizeText($segment['text']);
            $frequencies[$normalized] = ($frequencies[$normalized] ?? 0) + 1;
        }

        return array_values(array_filter(
            $segments,
            fn (array $segment): bool => ($frequencies[$this->normalizeText($segment['text'])] ?? 0) < 3
        ));
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text, " \t\n\r\0\x0B[]()"));

        return trim((string) preg_replace('/[\p{P}\p{Z}\s]+/u', ' ', $text));
    }
}
