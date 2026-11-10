<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One finished piece with its actual weights; carries the HUID once hallmarked. */
class FinishedItem extends Model
{
    use Audited, BelongsToTenant;

    public const IN_STOCK = 'in_stock';
    public const AT_AHC = 'at_ahc';
    public const REWORK = 'rework';
    public const DISPATCHED = 'dispatched';

    protected $attributes = ['status' => self::IN_STOCK, 'qc_status' => 'pending'];

    protected $fillable = [
        'tag_no', 'work_order_id', 'order_line_id', 'design_id', 'karat_id', 'size',
        'gross_wt', 'stone_wt', 'net_wt', 'purity_pct', 'fine_wt', 'storage_location',
    ];

    protected function casts(): array
    {
        return [
            'gross_wt' => 'decimal:3', 'stone_wt' => 'decimal:3', 'net_wt' => 'decimal:3',
            'purity_pct' => 'decimal:3', 'fine_wt' => 'decimal:3',
            'qc_checklist' => 'array', 'qc_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
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
