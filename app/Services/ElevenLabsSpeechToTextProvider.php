<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ElevenLabsSpeechToTextProvider implements SpeechToTextInterface
{
    private const ASSET_DISK = 'minio';
    private const TEMP_DISK = 'local';

    private string $apiKey;
    private string $apiEndpoint;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.elevenlabs.api_key') ?? env('ELEVENLABS_API_KEY');
        $this->apiEndpoint = rtrim(config('services.elevenlabs.endpoint') ?? env('ELEVENLABS_STT_ENDPOINT', 'https://api.elevenlabs.io/v1/speech-to-text'), '/');
        $this->model = config('services.elevenlabs.model') ?? env('ELEVENLABS_STT_MODEL', 'scribe_v1');

        if (!$this->apiKey) {
            throw new RuntimeException('ELEVENLABS_API_KEY not configured. Set ELEVENLABS_API_KEY environment variable.');
        }
    }

    public function transcribe(string $audioMinioPath): array
    {
        Log::info('ElevenLabsSpeechToTextProvider: starting transcription via ElevenLabs Scribe', [
            'audio_path' => $audioMinioPath,
        ]);

        $tempPath = 'temp/elevenlabs/chunk_' . uniqid() . '.mp3';

        try {
            $this->downloadAudioToTemp($audioMinioPath, $tempPath);
            $response = $this->callApi($tempPath);
            $segments = $this->extractSegments($response);

            Log::info('ElevenLabsSpeechToTextProvider: transcription complete', [
                'audio_path' => $audioMinioPath,
                'segment_count' => count($segments),
            ]);

            return $segments;
        } catch (Throwable $e) {
            Log::error('ElevenLabsSpeechToTextProvider: transcription failed', [
                'audio_path' => $audioMinioPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            try {
                Storage::disk(self::TEMP_DISK)->delete($tempPath);
            } catch (Throwable $e) {
                Log::warning('ElevenLabsSpeechToTextProvider: failed to cleanup temp file', [
                    'temp_path' => $tempPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function downloadAudioToTemp(string $minioPath, string $tempPath): void
    {
        try {
            $stream = Storage::disk(self::ASSET_DISK)->readStream($minioPath);

            if ($stream === false) {
                throw new RuntimeException("Cannot read audio from MinIO: {$minioPath}");
            }

            Storage::disk(self::TEMP_DISK)->writeStream($tempPath, $stream);
            fclose($stream);
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to download audio from MinIO: {$e->getMessage()}");
        }
    }

    private function callApi(string $tempPath): array
    {
        try {
            $absolutePath = Storage::disk(self::TEMP_DISK)->path($tempPath);

            Log::debug('ElevenLabsSpeechToTextProvider: calling ElevenLabs Scribe API', [
                'endpoint' => $this->apiEndpoint,
                'model' => $this->model,
            ]);

            $response = Http::withHeaders([
                'xi-api-key' => $this->apiKey,
            ])
                ->timeout(600)
                ->asMultipart()
                ->attach('file', fopen($absolutePath, 'rb'), 'audio.mp3')
                ->post($this->apiEndpoint, [
                    'model_id' => 'eleven_monolingual_v1',
                ]);

            if (!$response->successful()) {
                $errorMsg = $response->json('error.message') ?? $response->body();
                throw new RuntimeException(
                    "ElevenLabs STT error ({$response->status()}): {$errorMsg}"
                );
            }

            return $response->json();
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to call ElevenLabs STT API: {$e->getMessage()}");
        }
    }

    private function extractSegments(array $response): array
    {
        if (isset($response['words']) && is_array($response['words'])) {
            return $this->extractSegmentsFromWords($response['words']);
        }

        if (isset($response['segments']) && is_array($response['segments'])) {
            $segments = [];

            foreach ($response['segments'] as $segment) {
                if (!isset($segment['start'], $segment['end'], $segment['text'])) {
                    Log::warning('ElevenLabsSpeechToTextProvider: skipping segment with missing fields', [
                        'segment' => $segment,
                    ]);
                    continue;
                }

                $segments[] = [
                    'start' => (float) $segment['start'],
                    'end' => (float) $segment['end'],
                    'text' => (string) $segment['text'],
                ];
            }

            if (empty($segments) && isset($response['text']) && is_string($response['text']) && trim($response['text']) !== '') {
                return [[
                    'start' => 0.0,
                    'end' => 0.0,
                    'text' => trim($response['text']),
                ]];
            }

            return $segments;
        }

        if (isset($response['text']) && is_string($response['text']) && trim($response['text']) !== '') {
            return [[
                'start' => 0.0,
                'end' => 0.0,
                'text' => trim($response['text']),
            ]];
        }

        throw new RuntimeException(
            "Invalid ElevenLabs response: missing usable transcript data. Got: " . json_encode($response)
        );
    }

    private function extractSegmentsFromWords(array $words): array
    {
        $segments = [];
        $buffer = [];
        $bufferStart = null;
        $bufferEnd = null;

        foreach ($words as $word) {
            if (!is_array($word)) {
                continue;
            }

            $type = strtolower((string) ($word['type'] ?? 'word'));
            if ($type !== 'word') {
                continue;
            }

            $text = trim((string) ($word['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $start = isset($word['start']) ? (float) $word['start'] : 0.0;
            $end = isset($word['end']) ? (float) $word['end'] : $start;

            if ($bufferStart === null) {
                $bufferStart = $start;
                $bufferEnd = $end;
                $buffer[] = $text;
                continue;
            }

            if (($start - $bufferEnd) > 0.5) {
                $segments[] = [
                    'start' => (float) $bufferStart,
                    'end' => (float) $bufferEnd,
                    'text' => implode(' ', $buffer),
                ];

                $buffer = [$text];
                $bufferStart = $start;
                $bufferEnd = $end;
                continue;
            }

            $buffer[] = $text;
            $bufferEnd = $end;
        }

        if (!empty($buffer) && $bufferStart !== null && $bufferEnd !== null) {
            $segments[] = [
                'start' => (float) $bufferStart,
                'end' => (float) $bufferEnd,
                'text' => implode(' ', $buffer),
            ];
        }

        if (empty($segments)) {
            Log::info('ElevenLabsSpeechToTextProvider: no word-level segments created; returning empty transcript', []);
        }

        return $segments;
    }
}
