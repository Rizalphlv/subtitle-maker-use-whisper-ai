<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MinioPresigningConfigurationTest extends TestCase
{
    public function test_mobile_url_is_signed_directly_with_public_https_origin(): void
    {
        config(['filesystems.disks.minio_presign' => [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'bucket' => 'subtitles',
            'endpoint' => 'https://objects.example.com',
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]]);
        Storage::forgetDisk('minio_presign');

        $url = Storage::disk('minio_presign')->temporaryUrl('subtitle-jobs/sub_test/result.srt', now()->addHour());

        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertSame('objects.example.com', parse_url($url, PHP_URL_HOST));
        $this->assertStringContainsString('/subtitles/subtitle-jobs/sub_test/result.srt', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }
}
