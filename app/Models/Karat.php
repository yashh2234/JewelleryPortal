<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A karat with its touch / tunch %, e.g. 22K = 91.600. Defined per tenant. */
class Karat extends Model
{
    use Audited, BelongsToTenant;

    protected $attributes = ['is_active' => true, 'sort' => 0];

    protected $fillable = ['label', 'purity_pct', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['purity_pct' => 'decimal:3', 'is_active' => 'boolean'];
    }
}
