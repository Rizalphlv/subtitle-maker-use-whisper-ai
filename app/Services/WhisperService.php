<?php

namespace App\Services;

use RuntimeException;

/**
 * WhisperService
 *
 * This class now acts as a wrapper delegating to ElevenLabsSpeechToTextProvider.
 */
class WhisperService implements SpeechToTextInterface
{
    private ElevenLabsSpeechToTextProvider $provider;

    public function __construct()
    {
        $this->provider = new ElevenLabsSpeechToTextProvider();
    }

    public function transcribe(string $audioMinioPath): array
    {
        return $this->provider->transcribe($audioMinioPath);
    }
}
