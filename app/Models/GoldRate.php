<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Day rate per karat, set by the manufacturer; used for portal estimates. */
class GoldRate extends Model
{
    use Audited, BelongsToTenant;

    protected $fillable = ['date', 'karat_id', 'rate_per_g'];

    protected function casts(): array
    {
        // `date` stays a plain Y-m-d string so lookups compare the same way on SQLite and MySQL.
        return ['rate_per_g' => 'decimal:2'];
    }

    public function karat(): BelongsTo
    {
        return $this->belongsTo(Karat::class);
    }
}
