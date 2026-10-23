<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subtitles') || !Schema::hasColumn('subtitles', 'language')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `subtitles` MODIFY `language` VARCHAR(20) NOT NULL DEFAULT 'en' COMMENT 'Language of transcription (e.g. en, id, th, auto)'");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('subtitles') || !Schema::hasColumn('subtitles', 'language')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `subtitles` MODIFY `language` ENUM('en','id') NOT NULL DEFAULT 'en' COMMENT 'Language of transcription (legacy)'");
        }
    }
};
