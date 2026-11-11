<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** SaaS operator account ("platform" guard). Not tenant-scoped. */
class PlatformAdmin extends Authenticatable
{
    protected $attributes = ['remember_token' => null];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
