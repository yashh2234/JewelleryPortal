<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Making-charge slab assigned to retailers: per gram, per piece or % of metal value. */
class MakingSlab extends Model
{
    use Audited, BelongsToTenant;

    public const BASES = ['per_gram', 'per_piece', 'percent'];

    protected $fillable = ['name', 'basis', 'rate', 'is_default'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:3', 'is_default' => 'boolean'];
    }
}
