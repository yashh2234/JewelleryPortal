<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Ledger voucher. Append-only: only the reversal link and the kaarigar's
 * accept/dispute flag may change after posting; nothing is ever deleted.
 */
class MetalTransaction extends Model
{
    use BelongsToTenant;

    public const OPENING = 'opening';
    public const PURCHASE = 'purchase';
    public const DEPOSIT = 'deposit';
    public const ISSUE = 'issue';
    public const RECEIVE = 'receive';
    public const MELT = 'melt';
    public const REFINE = 'refine';
    public const TRANSFER = 'transfer';
    public const LOSS_WRITEOFF = 'loss_writeoff';
    public const REVERSAL = 'reversal';
    public const FINISH = 'finish';
    public const HALLMARK_SEND = 'hallmark_send';
    public const HALLMARK_RETURN = 'hallmark_return';
    public const DISPATCH = 'dispatch';

    /** Voucher series per type. */
    public const SERIES = [
        self::OPENING => 'OPN', self::PURCHASE => 'PUR', self::DEPOSIT => 'DEP', self::ISSUE => 'ISS',
        self::RECEIVE => 'RCV', self::MELT => 'MLT', self::REFINE => 'RFN', self::TRANSFER => 'TRF',
        self::LOSS_WRITEOFF => 'LOS', self::REVERSAL => 'REV', self::FINISH => 'FIN',
        self::HALLMARK_SEND => 'HMS', self::HALLMARK_RETURN => 'HMR', self::DISPATCH => 'DSP',
    ];

    private const MUTABLE = ['reversed_by_id', 'kaarigar_ack', 'kaarigar_ack_at', 'updated_at'];

    protected $fillable = [
        'voucher_no', 'type', 'date', 'job_card_id', 'work_order_id', 'kaarigar_id', 'process_id', 'retailer_id',
        'party_name', 'reference', 'remarks', 'over_limit', 'reverses_id',
    ];

    protected function casts(): array
    {
        return ['over_limit' => 'boolean', 'kaarigar_ack_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $tx) {
            $changed = array_diff(array_keys($tx->getDirty()), self::MUTABLE);
            if ($changed) {
                throw new LogicException('Ledger transactions are append-only; post a reversal instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('Ledger transactions cannot be deleted; post a reversal instead.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MetalTransactionLine::class);
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function kaarigar(): BelongsTo
    {
        return $this->belongsTo(Kaarigar::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }
}
