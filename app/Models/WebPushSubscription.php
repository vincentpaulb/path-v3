<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebPushSubscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'public_key' => 'encrypted', 'auth_token' => 'encrypted'];
    }
}
