<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HallmarkLotItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['hallmark_lot_id', 'finished_item_id', 'sent_wt', 'returned_wt', 'result', 'huid', 'remarks'];

    protected function casts(): array
    {
        return ['sent_wt' => 'decimal:3', 'returned_wt' => 'decimal:3'];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(HallmarkLot::class, 'hallmark_lot_id');
    }

    public function finishedItem(): BelongsTo
    {
        return $this->belongsTo(FinishedItem::class);
    }
}
