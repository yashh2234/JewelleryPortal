<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NormalisesPhone;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Kaarigar app login (phone + OTP, "kaarigar" guard). */
class KaarigarUser extends Authenticatable
{
    use Audited, BelongsToTenant, NormalisesPhone;

    protected $attributes = ['is_active' => true, 'is_master' => true, 'language' => 'hi', 'otp_attempts' => 0, 'remember_token' => null];

    protected $fillable = ['kaarigar_id', 'name', 'phone', 'is_master', 'language', 'is_active'];

    protected $hidden = ['otp_hash', 'otp_expires_at', 'otp_attempts', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_master' => 'boolean',
            'is_active' => 'boolean',
            'otp_expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function kaarigar(): BelongsTo
    {
        return $this->belongsTo(Kaarigar::class);
    }

    /** No password column: OTP only. */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function toAuthPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'language' => $this->language,
            'is_master' => $this->is_master,
            'kaarigar' => $this->kaarigar->only(['id', 'name', 'type']),
        ];
    }
}
