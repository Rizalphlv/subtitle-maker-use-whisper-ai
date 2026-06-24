<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VttGeneratorService
{
    /**
     * Convert SRT content to VTT format.
     * 
     * @param string $srtContent
     * @return string VTT content
     */
    public function convertSrtToVtt(string $srtContent): string
    {
        $vttContent = "WEBVTT\n\n";

        // Convert timestamps: 00:00:01,000 -> 00:00:01.000
        $vttContent .= preg_replace(
            '/(\d{2}:\d{2}:\d{2}),(\d{3})/',
            '$1.$2',
            $srtContent
        );

        return $vttContent;
    }

    /**
     * Generate VTT from an existing SRT path in MinIO and store it.
     * 
     * @param string $srtPath Path to SRT in MinIO
     * @return string Path to generated VTT in MinIO
     */
    public function generateFromSrt(string $srtPath): string
    {
        $vttPath = str_replace('.srt', '.vtt', $srtPath);

        try {
            $srtContent = Storage::disk('minio')->get($srtPath);
            $vttContent = $this->convertSrtToVtt($srtContent);

            // Crucial: Set Content-Type so browser knows it's a subtitle file
            Storage::disk('minio')->put($vttPath, $vttContent, [
                'ContentType' => 'text/vtt'
            ]);

            Log::info('VttGeneratorService: VTT generated successfully', [
                'srt_path' => $srtPath,
                'vtt_path' => $vttPath,
            ]);

            return $vttPath;
        } catch (\Exception $e) {
            Log::error('VttGeneratorService: failed to generate VTT', [
                'srt_path' => $srtPath,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException("Failed to generate VTT: {$e->getMessage()}");
        }
    }
}
