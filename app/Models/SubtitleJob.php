<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubtitleJob extends Model
{
    protected $guarded = [];

    protected $casts = ['input_size' => 'integer', 'processing_started_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime'];
}
