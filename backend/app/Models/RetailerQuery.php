<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A query, customisation request or complaint raised by a retailer from the portal. */
class RetailerQuery extends Model
{
    use Audited, BelongsToTenant;

    public const TYPES = ['query', 'customization', 'complaint'];

    public const STATUSES = ['open', 'answered', 'closed'];

    protected $attributes = ['status' => 'open', 'type' => 'query'];

    protected $fillable = ['ticket_no', 'retailer_id', 'retailer_user_id', 'order_id', 'type', 'subject', 'status'];

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RetailerQueryMessage::class)->oldest('id');
    }
}
