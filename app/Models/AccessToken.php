<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

class AccessToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'session_id'];
}
