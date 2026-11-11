<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One movement of fine gold from a location to a location. Immutable. */
class MetalTransactionLine extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** Header carries created_by. */
    protected bool $withoutCreatedBy = true;

    protected $fillable = [
        'metal_transaction_id', 'from_location_id', 'to_location_id', 'karat_id', 'kind',
        'gross_wt', 'stone_wt', 'findings_wt', 'net_wt', 'purity_pct', 'fine_wt', 'pieces',
    ];

    protected function casts(): array
    {
        return [
            'gross_wt' => 'decimal:3', 'stone_wt' => 'decimal:3', 'findings_wt' => 'decimal:3',
            'net_wt' => 'decimal:3', 'purity_pct' => 'decimal:3', 'fine_wt' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger lines are immutable.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(MetalTransaction::class, 'metal_transaction_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(MetalLocation::class, 'from_location_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(MetalLocation::class, 'to_location_id');
    }

    public function karat(): BelongsTo
    {
        return $this->belongsTo(Karat::class);
    }
}
