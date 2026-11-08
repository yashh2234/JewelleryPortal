<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use BelongsToTenant;

    protected bool $withoutCreatedBy = true;

    protected $fillable = ['retailer_id', 'retailer_user_id', 'design_id', 'karat_id', 'size', 'qty', 'target_wt_min', 'target_wt_max', 'remarks'];

    protected function casts(): array
    {
        return ['target_wt_min' => 'decimal:3', 'target_wt_max' => 'decimal:3'];
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function karat(): BelongsTo
    {
        return $this->belongsTo(Karat::class);
    }
}
