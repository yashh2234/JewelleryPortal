<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Anyone the business owes or is owed by, in money and/or metal: retailers,
 * bullion suppliers, kaarigars, refiners.
 */
class Party extends Model
{
    use Audited, BelongsToTenant;

    public const TYPES = ['retailer', 'supplier', 'kaarigar', 'refiner', 'other'];

    /** Control account (system_key) each party type posts to. */
    public const CONTROL = [
        'retailer' => 'debtors',
        'supplier' => 'creditors',
        'refiner' => 'creditors',
        'other' => 'creditors',
        'kaarigar' => 'kaarigars',
    ];

    protected $attributes = ['is_active' => true];

    protected $fillable = ['type', 'ref_type', 'ref_id', 'name', 'gstin', 'state_code', 'phone', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
