<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HallmarkLot extends Model
{
    use Audited, BelongsToTenant;

    public const DRAFT = 'draft';
    public const SENT = 'sent';
    public const RETURNED = 'returned';

    protected $attributes = ['status' => self::DRAFT];

    protected $fillable = ['lot_no', 'ahc_id', 'karat_id', 'manak_request_no', 'charges'];

    protected function casts(): array
    {
        return ['gross_wt' => 'decimal:3', 'charges' => 'decimal:2', 'sent_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    public function ahc(): BelongsTo
    {
        return $this->belongsTo(Ahc::class);
    }

    public function karat(): BelongsTo
    {
        return $this->belongsTo(Karat::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(HallmarkLotItem::class);
    }
}
