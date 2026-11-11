<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A production process (melting, casting, making, polishing…) with its allowed wastage. */
class Process extends Model
{
    use Audited, BelongsToTenant;

    protected $attributes = ['is_active' => true, 'sequence' => 0, 'default_wastage_pct' => '0'];

    protected $fillable = ['name', 'sequence', 'default_wastage_pct', 'is_active'];

    protected function casts(): array
    {
        return ['default_wastage_pct' => 'decimal:3', 'is_active' => 'boolean'];
    }

    public function wastageRules(): HasMany
    {
        return $this->hasMany(ProcessWastageRule::class);
    }

    /** Allowed wastage % for a karat: the per-karat override, else the process default. */
    public function allowedWastageFor(int $karatId): string
    {
        $rule = $this->relationLoaded('wastageRules')
            ? $this->wastageRules->firstWhere('karat_id', $karatId)
            : $this->wastageRules()->where('karat_id', $karatId)->first();

        return $rule?->allowed_wastage_pct ?? $this->default_wastage_pct;
    }
}
