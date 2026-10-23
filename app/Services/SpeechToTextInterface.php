<?php

namespace App\Services;

interface SpeechToTextInterface
{
    /**
     * Transcribe an audio chunk from MinIO.
     *
     * @param string $audioMinioPath MinIO path to audio chunk
     * @return array Structured result: ["segments" => [...], "language" => "en"]
     */
    public function transcribe(string $audioMinioPath): array;
}
