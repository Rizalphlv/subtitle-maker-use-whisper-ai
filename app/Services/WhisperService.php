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
<<<<<<< HEAD
    public function transcribe(string $audioMinioPath): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key not configured. Set OPENAI_API_KEY in .env.');
=======
    private const ASSET_DISK = 'minio';
    private const TEMP_DISK = 'local';

    private string $apiKey;
    private string $apiEndpoint;
    private string $model;

    public function __construct()
    {
        $this->apiKey       = config('services.groq.api_key') ?? env('GROQ_API_KEY');
        $this->apiEndpoint  = config('services.groq.endpoint') ?? env('GROQ_ENDPOINT', 'https://api.groq.com/openai/v1');
        $this->model        = config('services.groq.model') ?? env('GROQ_WHISPER_MODEL', 'whisper-large-v3');

        if (!$this->apiKey) {
            throw new RuntimeException('GROQ_API_KEY not configured. Set GROQ_API_KEY environment variable.');
        }
    }

    /**
     * Transcribe an audio chunk from MinIO using Whisper API.
     *
     * @param string $audioMinioPath  MinIO path to audio chunk
     * @param string $sourceLanguage  Source language code (e.g., 'auto', 'ja', 'ko')
     *
     * @return array  Segments array: [["start" => 0.0, "end" => 2.5, "text" => "..."], ...]
     *
     * @throws RuntimeException  If API call fails or response is invalid.
     */
    public function transcribe(string $audioMinioPath, string $sourceLanguage = 'auto'): array
    {
        Log::info('WhisperService: starting transcription (translate to English)', [
            'audio_path' => $audioMinioPath,
            'source_language' => $sourceLanguage,
        ]);

        $tempPath = "temp/whisper/chunk_" . uniqid() . ".mp3";

        try {
            // 1. Download audio chunk to temp storage.
            $this->downloadAudioToTemp($audioMinioPath, $tempPath);

            // 2. Call Groq Whisper translation API (auto-detect source, output English).
            $response = $this->callWhisperApi($tempPath, $sourceLanguage);

            // 3. Extract and validate segments.
            $segments = $this->extractSegments($response);

            Log::info('WhisperService: transcription complete', [
                'audio_path'   => $audioMinioPath,
                'segment_count' => count($segments),
            ]);

            return $segments;

        } catch (Throwable $e) {
            Log::error('WhisperService: transcription failed', [
                'audio_path' => $audioMinioPath,
                'error'      => $e->getMessage(),
            ]);

            throw $e;

        } finally {
            // Clean up temp file.
            try {
                Storage::disk(self::TEMP_DISK)->delete($tempPath);
            } catch (Throwable $e) {
                Log::warning('WhisperService: failed to cleanup temp file', [
                    'temp_path' => $tempPath,
                    'error'     => $e->getMessage(),
                ]);
            }
>>>>>>> c1ac1c3b3e6167fc4b2f826592671e4f6edd484d
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

<<<<<<< HEAD
            $path = Storage::disk('local')->path($tempPath);
            $audio = file_get_contents($path);
            if ($audio === false) {
                throw new RuntimeException("Cannot read temporary audio file: {$path}");
=======
            Storage::disk(self::TEMP_DISK)->writeStream($tempPath, $stream);
            fclose($stream);

        } catch (Throwable $e) {
            throw new RuntimeException("Failed to download audio from MinIO: {$e->getMessage()}");
        }
    }

    /**
     * Call Groq Whisper API with audio file.
     *
     * @return array  Parsed JSON response from Whisper API.
     *
     * @throws RuntimeException  If API call fails.
     */
    private function callWhisperApi(string $tempPath, string $sourceLanguage = 'auto'): array
    {
        try {
            $absolutePath = Storage::disk(self::TEMP_DISK)->path($tempPath);

            Log::debug('WhisperService: calling Groq Whisper translation API', [
                'endpoint' => $this->apiEndpoint,
                'model'    => $this->model,
            ]);

            // Use /audio/translations so output is always English
            // regardless of the source audio language.
            
            $payload = [
                'model'           => $this->model,
                'response_format' => 'verbose_json', // Includes detailed timing info
            ];

            if ($sourceLanguage !== 'auto') {
                $langNames = [
                    'ko' => 'Korean',
                    'ja' => 'Japanese',
                    'th' => 'Thai',
                    'zh' => 'Chinese'
                ];
                $langName = $langNames[$sourceLanguage] ?? $sourceLanguage;
                $payload['prompt'] = "The audio is in {$langName}. Please translate it to English.";
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])
            ->timeout(600) // 10 minute timeout for large files
            ->asMultipart()
            ->attach('file', fopen($absolutePath, 'rb'), 'audio.mp3')
            ->post("{$this->apiEndpoint}/audio/translations", $payload);

            if (!$response->successful()) {
                $errorMsg = $response->json('error.message') ?? $response->body();
                throw new RuntimeException(
                    "Whisper API error ({$response->status()}): {$errorMsg}"
                );
>>>>>>> c1ac1c3b3e6167fc4b2f826592671e4f6edd484d
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
