<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Catalogue collection / tag (New arrivals, Temple, Bridal). */
class Collection extends Model
{
    use Audited, BelongsToTenant;

    protected $fillable = ['name', 'slug', 'sort'];

    public function designs(): BelongsToMany
    {
        return $this->belongsToMany(Design::class);
    }
}
