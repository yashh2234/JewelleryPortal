<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Tenant extends Model
{
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'name', 'subdomain', 'gstin', 'state_code', 'phone', 'email', 'address',
        'logo_path', 'primary_color', 'plan_id', 'status', 'trial_ends_at', 'settings',
    ];

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function retailers(): HasMany
    {
        return $this->hasMany(Retailer::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE], true);
    }

    /** Trial over and not yet on a paid plan: the workspace becomes read-only. */
    public function isTrialExpired(): bool
    {
        return $this->status === self::STATUS_TRIAL && $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /** Public branding sent to the front ends and PWA manifests. */
    public function branding(): array
    {
        return [
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'logo_url' => $this->logo_url,
            'primary_color' => $this->primary_color,
            'status' => $this->status,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'read_only' => $this->isTrialExpired(),
        ];
    }
}
