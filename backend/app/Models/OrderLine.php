<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrderLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'order_id', 'design_id', 'karat_id', 'size', 'qty', 'target_wt_min', 'target_wt_max',
        'stone_details', 'remarks', 'reference_image',
    ];

    protected function casts(): array
    {
        return ['target_wt_min' => 'decimal:3', 'target_wt_max' => 'decimal:3'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function karat(): BelongsTo
    {
        return $this->belongsTo(Karat::class);
    }

    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'wo_order_lines')->withPivot('qty');
    }
}
