<?php

namespace Tests\Feature;

use App\Jobs\ProcessAudioSubtitleJob;
use App\Models\ApiClient;
use App\Models\SubtitleJob;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use Mockery;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class FailingSubtitleStreamWrapper
{
    public mixed $context;

    public static bool $closed = false;

    private int $reads = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->reads++ === 0) {
            return 'partial';
        }

        throw new \RuntimeException('internal stream secret');
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        self::$closed = true;
    }
}

class SubtitleJobApiTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $token = 'test-token'): array
    {
        $client = ApiClient::create(['name' => 'test', 'token_hash' => hash('sha256', $token)]);

        return [$client, ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }

    public function test_valid_audio_is_stored_persisted_and_dispatched(): void
    {
        Storage::fake('minio');
        Queue::fake();
        config(['subtitle_api.allowed_mimes' => ['application/octet-stream']]);
        [$client, $headers] = $this->client();

        $response = $this->withHeaders($headers)->post('/api/v1/subtitle-jobs', [
            'file' => UploadedFile::fake()->create('voice.mp3', 10, 'application/octet-stream'),
            'language' => 'auto',
        ]);

        $response->assertAccepted()->assertJsonPath('status', 'queued')->assertJsonStructure(['job_id', 'poll_interval_seconds']);
        $job = SubtitleJob::firstOrFail();
        $this->assertSame($client->id, $job->api_client_id);
        $this->assertStringStartsWith('sub_', $job->job_id);
        Storage::disk('minio')->assertExists($job->input_path);
        Queue::assertPushed(ProcessAudioSubtitleJob::class, fn ($queued) => $queued->subtitleJobId === $job->id);
    }

    public function test_upload_requires_authentication_and_valid_audio(): void
    {
        Storage::fake('minio');
        $this->postJson('/api/v1/subtitle-jobs')->assertUnauthorized();
        [, $headers] = $this->client();
        $this->withHeaders($headers)->post('/api/v1/subtitle-jobs', [
            'file' => UploadedFile::fake()->create('payload.exe', 1, 'application/octet-stream'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_oversized_audio_is_rejected(): void
    {
        Storage::fake('minio');
        config(['subtitle_api.max_upload_kb' => 1, 'subtitle_api.allowed_mimes' => ['application/octet-stream']]);
        [, $headers] = $this->client();
        $this->withHeaders($headers)->post('/api/v1/subtitle-jobs', [
            'file' => UploadedFile::fake()->create('voice.mp3', 2, 'application/octet-stream'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_status_states_and_safe_failure_are_returned(): void
    {
        Storage::fake('minio');
        [$client, $headers] = $this->client();
        $job = $this->makeJob($client->id, 'processing', 'transcribing');
        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/'.$job->job_id)
            ->assertOk()->assertJsonPath('stage', 'transcribing')->assertJsonPath('download_url', null);

        $job->update(['status' => 'failed', 'error_code' => 'TRANSCRIPTION_FAILED', 'error_message' => 'secret internals']);
        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/'.$job->job_id)
            ->assertOk()->assertJsonPath('error.code', 'TRANSCRIPTION_FAILED')
            ->assertJsonMissing(['message' => 'secret internals']);
    }

    public function test_completed_status_returns_authenticated_laravel_download_url(): void
    {
        Storage::fake('minio');
        Storage::fake('minio_presign');
        Storage::disk('minio_presign')->buildTemporaryUrlsUsing(fn ($path, $expires) => 'https://storage.example.com/'.$path.'?expires='.$expires->timestamp);
        [$client, $headers] = $this->client();
        $job = $this->makeJob($client->id, 'completed', 'completed');
        $job->update(['output_disk' => 'minio', 'output_path' => "subtitle-jobs/{$job->job_id}/result.srt"]);

        $response = $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/'.$job->job_id)
            ->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('expires_at', null)
            ->assertJsonMissing(['download_url' => 'https://storage.example.com/'.$job->output_path])
            ->assertJsonMissingPath('download_path');
        $downloadUrl = $response->json('download_url');
        $this->assertSame('/api/v1/subtitle-jobs/'.$job->job_id.'/download', $downloadUrl);
        $this->assertStringNotContainsString('s3.amazonaws.com', $downloadUrl);
        $this->assertStringNotContainsString('minio', $downloadUrl);
    }

    public function test_completed_job_download_streams_srt_with_safe_headers(): void
    {
        Storage::fake('minio');
        [$client, $headers] = $this->client();
        $job = $this->completedJob($client->id, '..\\unsafe/voice"\r\nInjected: yes.mp3');
        Storage::disk('minio')->put($job->output_path, "1\n00:00:00,000 --> 00:00:01,000\nHello\n");

        $response = $this->withHeaders($headers)->get('/api/v1/subtitle-jobs/'.$job->job_id.'/download');

        $response->assertOk()
            ->assertHeader('content-type', 'application/x-subrip; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $disposition = $response->headers->get('content-disposition');
        $this->assertMatchesRegularExpression('/^attachment; filename=[A-Za-z0-9._-]+\.srt$/', $disposition);
        $this->assertStringNotContainsString('Injected:', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString('\\', $disposition);
        $this->assertStringNotContainsString('/', $disposition);
        $this->assertStringContainsString('private', $response->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertSame("1\n00:00:00,000 --> 00:00:01,000\nHello\n", $response->streamedContent());
        $this->assertFalse($response->isRedirect());
        $this->assertStringNotContainsString($job->output_path, (string) $response->baseResponse->headers);
    }

    public function test_download_requires_a_valid_non_revoked_client(): void
    {
        $this->getJson('/api/v1/subtitle-jobs/sub_valid/download')->assertUnauthorized();
        $this->withToken('invalid')->getJson('/api/v1/subtitle-jobs/sub_valid/download')->assertUnauthorized();
        [$client, $headers] = $this->client('revoked');
        $client->update(['revoked_at' => now()]);
        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/sub_valid/download')->assertUnauthorized();
    }

    public function test_download_rejects_invalid_unknown_and_cross_client_job_ids_without_disclosure(): void
    {
        [$owner] = $this->client('download-owner');
        [, $headers] = $this->client('download-other');
        $job = $this->completedJob($owner->id);

        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/not-a-job/download')->assertNotFound();
        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/sub_unknown/download')->assertNotFound();
        $this->withHeader('Authorization', $headers['Authorization'])
            ->get('/api/v1/subtitle-jobs/sub_unknown/download')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertJson(['message' => 'The subtitle job was not found.']);
        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/'.$job->job_id.'/download')
            ->assertNotFound()
            ->assertJsonMissing(['output_path' => $job->output_path]);
    }

    public function test_download_rejects_non_completed_jobs_with_safe_conflicts(): void
    {
        [$client, $headers] = $this->client();
        foreach (['queued', 'processing', 'failed'] as $status) {
            $job = $this->makeJob($client->id, $status, $status === 'processing' ? 'transcribing' : null);
            $job->update(['error_message' => 'http://minio:9000/private-secret']);

            $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/'.$job->job_id.'/download')
                ->assertConflict()
                ->assertJsonMissing(['error_message' => $job->error_message])
                ->assertJsonMissing(['path' => $job->output_path]);
        }
    }

    public function test_download_returns_not_found_for_missing_output_reference_or_object(): void
    {
        Storage::fake('minio');
        [$client, $headers] = $this->client();
        $withoutReference = $this->makeJob($client->id, 'completed', 'completed');
        $missingObject = $this->completedJob($client->id);

        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/'.$withoutReference->job_id.'/download')->assertNotFound();
        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/'.$missingObject->job_id.'/download')
            ->assertNotFound()
            ->assertJsonMissing(['path' => $missingObject->output_path]);
    }

    public function test_download_returns_safe_service_error_when_storage_cannot_be_read(): void
    {
        Log::spy();
        [$client, $headers] = $this->client();
        $job = $this->completedJob($client->id);
        $disk = Mockery::mock();
        $exception = new class('internal minio secret') extends \RuntimeException implements FilesystemException {};
        $disk->shouldReceive('exists')->once()->with($job->output_path)->andReturnTrue();
        $disk->shouldReceive('readStream')->once()->with($job->output_path)->andThrow($exception);
        Storage::shouldReceive('disk')->once()->with('minio')->andReturn($disk);

        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/'.$job->job_id.'/download')
            ->assertStatus(503)
            ->assertJson(['message' => 'The subtitle file is temporarily unavailable.'])
            ->assertJsonMissing(['message' => 'internal minio secret'])
            ->assertJsonMissing(['path' => $job->output_path]);
        Log::shouldHaveReceived('warning')->once()->with(
            'Subtitle download storage read failed.',
            ['job_id' => $job->job_id, 'exception' => get_class($exception)],
        );
    }

    public function test_download_closes_the_source_stream_after_streaming(): void
    {
        [$client, $headers] = $this->client();
        $job = $this->completedJob($client->id, 'audio.mp3');
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "SRT body\n");
        rewind($stream);
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with($job->output_path)->andReturnTrue();
        $disk->shouldReceive('readStream')->once()->with($job->output_path)->andReturn($stream);
        Storage::shouldReceive('disk')->once()->with('minio')->andReturn($disk);

        $response = $this->withHeaders($headers)->get('/api/v1/subtitle-jobs/'.$job->job_id.'/download');
        $this->assertSame("SRT body\n", $response->streamedContent());
        $this->assertFalse(is_resource($stream));
    }

    public function test_download_closes_and_safely_logs_a_mid_stream_read_failure(): void
    {
        Log::spy();
        [$client, $headers] = $this->client();
        $job = $this->completedJob($client->id);
        FailingSubtitleStreamWrapper::$closed = false;
        stream_wrapper_register('failing-srt', FailingSubtitleStreamWrapper::class);
        $stream = fopen('failing-srt://result', 'rb');
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with($job->output_path)->andReturnTrue();
        $disk->shouldReceive('readStream')->once()->with($job->output_path)->andReturn($stream);
        Storage::shouldReceive('disk')->once()->with('minio')->andReturn($disk);

        try {
            $response = $this->withHeaders($headers)->get('/api/v1/subtitle-jobs/'.$job->job_id.'/download');
            $this->assertSame('partial', $response->streamedContent());
            $this->assertTrue(FailingSubtitleStreamWrapper::$closed);
            Log::shouldHaveReceived('warning')->once()->with(
                'Subtitle download stream interrupted.',
                ['job_id' => $job->job_id, 'exception' => \RuntimeException::class],
            );
        } finally {
            stream_wrapper_unregister('failing-srt');
        }
    }

    public function test_another_client_cannot_access_job(): void
    {
        [$owner] = $this->client('owner');
        [, $otherHeaders] = $this->client('other');
        $job = $this->makeJob($owner->id, 'queued', null);
        $this->withHeaders($otherHeaders)->getJson('/api/v1/subtitle-jobs/'.$job->job_id)->assertNotFound();
    }

    public function test_dispatch_failure_marks_durable_job_failed_and_retains_input(): void
    {
        Storage::fake('minio');
        config(['subtitle_api.allowed_mimes' => ['application/octet-stream']]);
        [, $headers] = $this->client();
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('push')->once()->andReturnUsing(function () {
            DB::afterCommit(fn () => throw new \RuntimeException('redis unavailable after commit'));

            return 'pending-dispatch';
        });
        Queue::shouldReceive('connection')->andReturn($queue);

        $this->withHeaders($headers)->post('/api/v1/subtitle-jobs', [
            'file' => UploadedFile::fake()->create('voice.mp3', 10, 'application/octet-stream'),
        ])->assertStatus(503)->assertJson(['message' => 'The subtitle job could not be accepted.']);

        $job = SubtitleJob::firstOrFail();
        $this->assertSame('failed', $job->status);
        $this->assertSame('DISPATCH_FAILED', $job->error_code);
        Storage::disk('minio')->assertExists($job->input_path);
    }

    private function makeJob(int $clientId, string $status, ?string $stage): SubtitleJob
    {
        return SubtitleJob::create([
            'api_client_id' => $clientId, 'job_id' => 'sub_'.strtolower(Str::random(20)),
            'status' => $status, 'stage' => $stage, 'language' => 'auto', 'output_format' => 'srt',
            'input_disk' => 'minio', 'input_path' => 'input/audio.mp3', 'input_filename' => 'audio.mp3',
            'input_mime_type' => 'audio/mpeg', 'input_size' => 10,
        ]);
    }

    private function completedJob(int $clientId, string $filename = 'audio.mp3'): SubtitleJob
    {
        $job = $this->makeJob($clientId, 'completed', 'completed');
        $job->update([
            'input_filename' => $filename,
            'output_disk' => 'minio',
            'output_path' => "subtitle-jobs/{$job->job_id}/result.srt",
        ]);

        return $job->refresh();
    }
}
