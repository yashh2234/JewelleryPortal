<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Labour per process (₹ per gram or per piece); a kaarigar-specific rate overrides the process rate. */
class LabourRate extends Model
{
    use Audited, BelongsToTenant;

    protected $fillable = ['process_id', 'kaarigar_id', 'basis', 'rate'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2'];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function kaarigar(): BelongsTo
    {
        return $this->belongsTo(Kaarigar::class);
    }

    public static function for(int $processId, int $kaarigarId): ?self
    {
        return self::where('process_id', $processId)
            ->where(fn ($q) => $q->where('kaarigar_id', $kaarigarId)->orWhereNull('kaarigar_id'))
            ->orderByRaw('kaarigar_id is null')
            ->first();
    }
}
