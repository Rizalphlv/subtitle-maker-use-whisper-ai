<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_id', 40)->unique();
            $table->string('status', 20)->default('queued')->index();
            $table->string('stage', 40)->nullable();
            $table->string('language', 20)->default('auto');
            $table->string('output_format', 10)->default('srt');
            $table->string('input_disk', 40);
            $table->string('input_path');
            $table->string('input_filename');
            $table->string('input_mime_type', 100);
            $table->unsignedBigInteger('input_size');
            $table->string('output_disk', 40)->nullable();
            $table->string('output_path')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['api_client_id', 'job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_jobs');
    }
};
