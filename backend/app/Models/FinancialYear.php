<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FinancialYear extends Model
{
    use Audited, BelongsToTenant;

    protected $fillable = ['label', 'start_date', 'end_date', 'locked_until'];

    protected function casts(): array
    {
        return [];   // dates stay Y-m-d strings so comparisons work the same on SQLite and MySQL
    }
}
