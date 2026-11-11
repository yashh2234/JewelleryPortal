<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A design the retailer saved for later (shared by the shop's users). */
class WishlistItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['retailer_id', 'design_id'];

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }
}
