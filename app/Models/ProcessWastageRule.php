<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessWastageRule extends Model
{
    use Audited, BelongsToTenant;

    protected $fillable = ['process_id', 'karat_id', 'allowed_wastage_pct'];

    protected function casts(): array
    {
        return ['allowed_wastage_pct' => 'decimal:3'];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function karat(): BelongsTo
    {
        return $this->belongsTo(Karat::class);
    }
}
