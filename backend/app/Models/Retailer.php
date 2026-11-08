<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Retailer extends Model
{
    use Audited, BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REJECTED = 'rejected';

    protected $attributes = ['status' => self::STATUS_PENDING, 'credit_limit' => '0', 'metal_account_enabled' => false, 'hallmark_mandatory' => true];

    protected $fillable = [
        'shop_name', 'contact_name', 'gstin', 'state_code', 'city', 'address', 'phone', 'email',
        'credit_limit', 'making_slab_id', 'metal_account_enabled', 'hallmark_mandatory', 'status',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'metal_account_enabled' => 'boolean',
            'hallmark_mandatory' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(RetailerUser::class);
    }

    /** Collections this retailer may see; none = every published design. */
    public function collections(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Collection::class);
    }

    public function makingSlab(): BelongsTo
    {
        return $this->belongsTo(MakingSlab::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
