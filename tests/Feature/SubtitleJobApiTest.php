<?php

namespace Tests\Feature;

use App\Jobs\ProcessAudioSubtitleJob;
use App\Models\ApiClient;
use App\Models\SubtitleJob;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

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

    public function test_completed_status_returns_fresh_temporary_url(): void
    {
        Storage::fake('minio');
        Storage::fake('minio_presign');
        Storage::disk('minio_presign')->buildTemporaryUrlsUsing(fn ($path, $expires) => 'https://storage.example.com/'.$path.'?expires='.$expires->timestamp);
        [$client, $headers] = $this->client();
        $job = $this->makeJob($client->id, 'completed', 'completed');
        $job->update(['output_disk' => 'minio', 'output_path' => "subtitle-jobs/{$job->job_id}/result.srt"]);

        $this->withHeaders($headers)->getJson('/api/v1/subtitle-jobs/'.$job->job_id)
            ->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('download_url', fn ($url) => str_starts_with($url, 'https://storage.example.com/'))
            ->assertJsonStructure(['download_url', 'expires_at']);
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
        $queue = \Mockery::mock(QueueContract::class);
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
}
