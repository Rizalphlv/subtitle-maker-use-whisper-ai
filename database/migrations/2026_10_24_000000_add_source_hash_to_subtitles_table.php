<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitles', function (Blueprint $table) {
            $table->string('source_hash', 64)->nullable()->after('raw_transcript');
        });
    }

    public function down(): void
    {
        Schema::table('subtitles', function (Blueprint $table) {
            $table->dropColumn('source_hash');
        });
    }
};
