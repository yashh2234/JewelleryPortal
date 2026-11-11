<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected bool $withoutCreatedBy = true;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'gross_wt' => 'decimal:3', 'net_wt' => 'decimal:3', 'purity_pct' => 'decimal:3', 'fine_wt' => 'decimal:3',
            'rate' => 'decimal:2', 'metal_value' => 'decimal:2', 'making' => 'decimal:2', 'stone_charge' => 'decimal:2',
            'taxable' => 'decimal:2', 'gst_rate' => 'decimal:2',
            'cgst' => 'decimal:2', 'sgst' => 'decimal:2', 'igst' => 'decimal:2', 'total' => 'decimal:2',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function finishedItem(): BelongsTo
    {
        return $this->belongsTo(FinishedItem::class);
    }
}
