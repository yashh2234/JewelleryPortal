<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A craftsman or an outside karkhana. Holds gold as a ledger location (Module 1). */
class Kaarigar extends Model
{
    use Audited, BelongsToTenant;

    public const TYPE_IN_HOUSE = 'in_house';
    public const TYPE_OUTSIDE = 'outside';

    protected $attributes = ['is_active' => true, 'type' => self::TYPE_IN_HOUSE];

    protected $fillable = ['name', 'phone', 'type', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function appUsers(): HasMany
    {
        return $this->hasMany(KaarigarUser::class);
    }
}
