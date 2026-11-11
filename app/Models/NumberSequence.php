<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class NumberSequence extends Model
{
    use BelongsToTenant;

    protected $fillable = ['series', 'financial_year', 'last_number'];
}
