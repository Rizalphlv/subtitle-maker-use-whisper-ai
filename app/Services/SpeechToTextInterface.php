<?php

namespace App\Services;

interface SpeechToTextInterface
{
    /**
     * Transcribe an audio chunk from MinIO.
     *
     * @param string $audioMinioPath MinIO path to audio chunk
     * @return array Segments array: [["start" => 0.0, "end" => 2.5, "text" => "..."], ...]
     */
    public function transcribe(string $audioMinioPath): array;
}
