<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A craftsman or an outside karkhana. Holds gold as a ledger location (Module 1).
 * A head kaarigar can pass work and gold to the sub-kaarigars under him (blueprint §2).
 */
class Kaarigar extends Model
{
    use Audited, BelongsToTenant;

    public const TYPE_IN_HOUSE = 'in_house';

    public const TYPE_OUTSIDE = 'outside';

    protected $attributes = ['is_active' => true, 'type' => self::TYPE_IN_HOUSE];

    protected $fillable = ['name', 'phone', 'type', 'head_kaarigar_id', 'address', 'is_active', 'max_fine_balance'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'max_fine_balance' => 'decimal:3'];
    }

    public function appUsers(): HasMany
    {
        return $this->hasMany(KaarigarUser::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(self::class, 'head_kaarigar_id');
    }

    public function subKaarigars(): HasMany
    {
        return $this->hasMany(self::class, 'head_kaarigar_id');
    }

    public function isHead(): bool
    {
        return $this->subKaarigars()->where('is_active', true)->exists();
    }
}
