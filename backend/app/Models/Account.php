<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A ledger account in the chart of accounts. system_key marks accounts used by automatic postings. */
class Account extends Model
{
    use Audited, BelongsToTenant;

    public const NATURES = ['asset', 'liability', 'equity', 'income', 'expense'];

    protected $attributes = ['is_active' => true];

    protected $fillable = ['code', 'name', 'nature', 'group', 'system_key', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Debit-normal accounts show Dr − Cr as a positive balance. */
    public function isDebitNormal(): bool
    {
        return in_array($this->nature, ['asset', 'expense'], true);
    }
}
