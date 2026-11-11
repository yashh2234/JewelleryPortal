<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A place gold can be: vault, a kaarigar, scrap pool, finished goods, loss, outside… */
class MetalLocation extends Model
{
    use BelongsToTenant;

    public const VAULT = 'vault';

    public const KAARIGAR = 'kaarigar';

    public const WO = 'wo';

    public const SCRAP = 'scrap';

    public const FINISHED = 'finished';

    public const REFINER = 'refiner';

    public const MELT = 'melt';

    public const LOSS = 'loss';

    public const OUTSIDE = 'outside';

    /** Physical stock that can never go below zero. */
    public const STOCK = [self::VAULT, self::SCRAP, self::FINISHED, self::KAARIGAR, self::WO];

    /** Locations that are the business's own gold (excludes outside and loss). */
    public const HELD = [self::VAULT, self::KAARIGAR, self::WO, self::SCRAP, self::FINISHED, self::REFINER, self::MELT];

    protected $fillable = ['type', 'name', 'ref_type', 'ref_id'];

    public function isStock(): bool
    {
        return in_array($this->type, self::STOCK, true);
    }
}
