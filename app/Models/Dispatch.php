<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Delivery challan: finished pieces leaving for a retailer. Billed in Module 4. */
class Dispatch extends Model
{
    use Audited, BelongsToTenant;

    protected $fillable = ['challan_no', 'retailer_id', 'order_id', 'date', 'pieces', 'gross_wt', 'net_wt', 'fine_wt', 'metal_transaction_id', 'remarks'];

    protected function casts(): array
    {
        return ['gross_wt' => 'decimal:3', 'net_wt' => 'decimal:3', 'fine_wt' => 'decimal:3', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    protected $attributes = ['shipment_status' => 'dispatched'];

    /** Shipment documents: courier receipt, insurance, photos of the sealed parcel. */
    public function documents(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DispatchLine::class);
    }
}
