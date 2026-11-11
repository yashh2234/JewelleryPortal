<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** GST rate per HSN, effective from a date. Lives in settings, not code; the CA confirms it. */
class TaxSetting extends Model
{
    use Audited, BelongsToTenant;

    protected $fillable = ['hsn', 'description', 'gst_rate', 'effective_from'];

    protected function casts(): array
    {
        return ['gst_rate' => 'decimal:2'];
    }

    public static function rateFor(string $hsn, string $date): string
    {
        return (string) (self::where('hsn', $hsn)->where('effective_from', '<=', $date)->orderByDesc('effective_from')->value('gst_rate') ?? '0');
    }
}
