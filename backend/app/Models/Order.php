<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use Audited, BelongsToTenant;

    public const PLACED = 'placed';
    public const CONFIRMED = 'confirmed';
    public const IN_PRODUCTION = 'in_production';
    public const READY = 'ready';
    public const PARTIALLY_DISPATCHED = 'partially_dispatched';
    public const DISPATCHED = 'dispatched';
    public const CLOSED = 'closed';
    public const ON_HOLD = 'on_hold';
    public const CANCELLED = 'cancelled';

    protected $attributes = ['status' => self::PLACED, 'source' => 'staff'];

    protected $fillable = ['order_no', 'retailer_id', 'source', 'due_date', 'remarks'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'confirmed_at' => 'datetime'];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }
}
