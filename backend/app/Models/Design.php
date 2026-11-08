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

    protected $attributes = ['is_published' => false];

    protected $fillable = [
        'code', 'name', 'category_id', 'approx_weight_min', 'approx_weight_max', 'sizes',
        'making_charge', 'making_type', 'description', 'is_published',
    ];

    protected function casts(): array
    {
        return [
            'approx_weight_min' => 'decimal:3',
            'approx_weight_max' => 'decimal:3',
            'making_charge' => 'decimal:3',
            'sizes' => 'array',
            'is_published' => 'boolean',
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
