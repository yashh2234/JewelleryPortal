<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Accounting voucher. Never edited or deleted once posted; cancelled by a reversal voucher. */
class Voucher extends Model
{
    use BelongsToTenant;

    public const SALES = 'sales';
    public const PURCHASE = 'purchase';
    public const RECEIPT = 'receipt';
    public const PAYMENT = 'payment';
    public const METAL_RECEIPT = 'metal_receipt';
    public const METAL_PAYMENT = 'metal_payment';
    public const RATE_CUT = 'rate_cut';
    public const LABOUR = 'labour';
    public const JOURNAL = 'journal';
    public const CONTRA = 'contra';
    public const CREDIT_NOTE = 'credit_note';
    public const DEBIT_NOTE = 'debit_note';
    public const REVERSAL = 'reversal';

    public const SERIES = [
        self::SALES => 'INV', self::PURCHASE => 'PB', self::RECEIPT => 'RV', self::PAYMENT => 'PV',
        self::METAL_RECEIPT => 'MR', self::METAL_PAYMENT => 'MP', self::RATE_CUT => 'RC', self::LABOUR => 'LB',
        self::JOURNAL => 'JV', self::CONTRA => 'CV', self::CREDIT_NOTE => 'CN', self::DEBIT_NOTE => 'DN',
        self::REVERSAL => 'RVS',
    ];

    private const MUTABLE = ['status', 'cancelled_by_id', 'meta', 'updated_at'];

    protected $fillable = [
        'voucher_no', 'type', 'date', 'financial_year_id', 'party_id', 'narration', 'source_type', 'source_id',
        'reverses_id', 'amount', 'fine_wt', 'meta',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'fine_wt' => 'decimal:3', 'meta' => 'array'];   // date stays a Y-m-d string
    }

    protected static function booted(): void
    {
        static::updating(function (self $v) {
            if (array_diff(array_keys($v->getDirty()), self::MUTABLE)) {
                throw new LogicException('Posted vouchers cannot be edited; cancel and re-enter.');
            }
        });
        static::deleting(fn () => throw new LogicException('Vouchers cannot be deleted; cancel instead.'));
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class);
    }

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
