<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** BIS-recognised Assaying & Hallmarking Centre. */
class Ahc extends Model
{
    use Audited, BelongsToTenant;

    protected $attributes = ['is_active' => true, 'charge_per_piece' => '0'];

    protected $fillable = ['name', 'bis_recognition_no', 'city', 'contact', 'charge_per_piece', 'is_active'];

    protected function casts(): array
    {
        return ['charge_per_piece' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
