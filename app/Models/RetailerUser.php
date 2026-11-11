<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NormalisesPhone;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Retailer portal login ("retailer" guard). Never reaches manufacturer routes. */
class RetailerUser extends Authenticatable
{
    use Audited, BelongsToTenant, NormalisesPhone;

    protected $attributes = ['is_active' => true, 'role' => 'owner', 'remember_token' => null];

    protected $fillable = ['retailer_id', 'name', 'email', 'phone', 'password', 'role', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean'];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function toAuthPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'retailer' => $this->retailer->only(['id', 'shop_name', 'city', 'status', 'metal_account_enabled']),
        ];
    }
}
