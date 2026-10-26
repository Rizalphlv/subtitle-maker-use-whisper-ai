<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    protected $fillable = ['name', 'token_hash', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
}
