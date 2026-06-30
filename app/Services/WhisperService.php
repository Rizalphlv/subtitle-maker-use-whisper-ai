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
 * Wrapper around ElevenLabs Speech-to-Text (Scribe) for audio transcription.
 *
 * Responsibilities:
 *  1. Download audio chunk from MinIO
 *  2. Send to ElevenLabs STT API
 *  3. Parse JSON response
 *  4. Extract normalized segments with text and timing
 */
class WhisperService
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

    /**
     * Transcribe an audio chunk from MinIO using ElevenLabs Scribe.
     *
     * @param string $audioMinioPath MinIO path to audio chunk
     *
     * @return array Segments array: [["start" => 0.0, "end" => 2.5, "text" => "..."], ...]
     *
     * @throws RuntimeException If API call fails or response is invalid.
     */
    public function transcribe(string $audioMinioPath): array
    {
        Log::info('WhisperService: starting transcription via ElevenLabs Scribe', [
            'audio_path' => $audioMinioPath,
        ]);

        $tempPath = 'temp/whisper/chunk_' . uniqid() . '.mp3';

        try {
            $this->downloadAudioToTemp($audioMinioPath, $tempPath);
            $response = $this->callWhisperApi($tempPath);
            $segments = $this->extractSegments($response);

            Log::info('WhisperService: transcription complete', [
                'audio_path' => $audioMinioPath,
                'segment_count' => count($segments),
            ]);

            return $segments;
        } catch (Throwable $e) {
            Log::error('WhisperService: transcription failed', [
                'audio_path' => $audioMinioPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            try {
                Storage::disk(self::TEMP_DISK)->delete($tempPath);
            } catch (Throwable $e) {
                Log::warning('WhisperService: failed to cleanup temp file', [
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

    /**
     * Call ElevenLabs Scribe API with audio file.
     *
     * @return array Parsed JSON response from the provider.
     *
     * @throws RuntimeException If API call fails.
     */
    private function callWhisperApi(string $tempPath): array
    {
        try {
            $absolutePath = Storage::disk(self::TEMP_DISK)->path($tempPath);

            Log::debug('WhisperService: calling ElevenLabs Scribe API', [
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
                    'model' => $this->model,
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

    /**
     * Extract normalized segments from ElevenLabs response.
     *
     * ElevenLabs returns word-level tokens, so we convert those into the segment
     * structure expected by the existing subtitle pipeline.
     *
     * @return array Normalized segments: [["start" => float, "end" => float, "text" => string], ...]
     */
    private function extractSegments(array $response): array
    {
        if (isset($response['words']) && is_array($response['words'])) {
            return $this->extractSegmentsFromWords($response['words']);
        }

        if (isset($response['segments']) && is_array($response['segments'])) {
            $segments = [];

            foreach ($response['segments'] as $segment) {
                if (!isset($segment['start'], $segment['end'], $segment['text'])) {
                    Log::warning('WhisperService: skipping segment with missing fields', [
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
            Log::info('WhisperService: no word-level segments created; returning empty transcript', []);
        }

        return $segments;
    }
}
