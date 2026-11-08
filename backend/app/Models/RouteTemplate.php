<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ordered list of process stages for a product type; copied onto each work order. */
class RouteTemplate extends Model
{
    use Audited, BelongsToTenant;

    protected $fillable = ['name', 'category_id', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RouteTemplateStep::class)->orderBy('sequence');
    }
}
