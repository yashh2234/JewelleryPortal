<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherEntry extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected bool $withoutCreatedBy = true;

    protected $fillable = ['voucher_id', 'account_id', 'party_id', 'debit', 'credit', 'fine_dr', 'fine_cr', 'memo'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'fine_dr' => 'decimal:3', 'fine_cr' => 'decimal:3'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
