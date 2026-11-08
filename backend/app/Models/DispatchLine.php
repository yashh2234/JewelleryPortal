<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['dispatch_id', 'finished_item_id', 'order_line_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinishedItem::class, 'finished_item_id');
    }
}
