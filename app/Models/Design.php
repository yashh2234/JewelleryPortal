<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Design extends Model
{
    use Audited, BelongsToTenant;

    public const AVAILABILITY = ['in_stock', 'made_to_order', 'unavailable'];

    protected $attributes = ['is_published' => false, 'min_order_qty' => 1, 'availability' => 'made_to_order'];

    protected $fillable = [
        'code', 'name', 'category_id', 'approx_weight_min', 'approx_weight_max', 'sizes',
        'making_charge', 'making_type', 'description', 'is_published',
        'stone_details', 'specifications', 'min_order_qty', 'production_days', 'availability',
    ];

    protected function casts(): array
    {
        return [
            'approx_weight_min' => 'decimal:3',
            'approx_weight_max' => 'decimal:3',
            'making_charge' => 'decimal:3',
            'sizes' => 'array',
            'is_published' => 'boolean',
            'min_order_qty' => 'integer',
            'production_days' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function karats(): BelongsToMany
    {
        return $this->belongsToMany(Karat::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(DesignImage::class)->orderBy('sort');
    }
}
