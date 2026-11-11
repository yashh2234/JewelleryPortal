<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order lifecycle (spec §4):
 *   new → pending_approval → approved                         (people)
 *   → work_order_created → material_issued → in_production
 *   → under_qc ⇄ rework_required → production_completed
 *   → ready_for_dispatch                                      (worked out from production: OrderProgress)
 *   → partially_dispatched → dispatched → delivered           (from dispatches / shipments)
 *   → closed                                                  (people)
 * plus on_hold and cancelled at any point before dispatch.
 */
class Order extends Model
{
    use Audited, BelongsToTenant;

    public const NEW = 'new';
    public const PENDING_APPROVAL = 'pending_approval';
    public const APPROVED = 'approved';
    public const WORK_ORDER_CREATED = 'work_order_created';
    public const MATERIAL_ISSUED = 'material_issued';
    public const IN_PRODUCTION = 'in_production';
    public const UNDER_QC = 'under_qc';
    public const REWORK_REQUIRED = 'rework_required';
    public const PRODUCTION_COMPLETED = 'production_completed';
    public const READY_FOR_DISPATCH = 'ready_for_dispatch';
    public const PARTIALLY_DISPATCHED = 'partially_dispatched';
    public const DISPATCHED = 'dispatched';
    public const DELIVERED = 'delivered';
    public const CLOSED = 'closed';
    public const ON_HOLD = 'on_hold';
    public const CANCELLED = 'cancelled';

    /** Statuses OrderProgress may move between on its own. */
    public const PRODUCTION = [
        self::WORK_ORDER_CREATED, self::MATERIAL_ISSUED, self::IN_PRODUCTION, self::UNDER_QC,
        self::REWORK_REQUIRED, self::PRODUCTION_COMPLETED, self::READY_FOR_DISPATCH,
    ];

    /** Every status in lifecycle order (for timelines and filters). */
    public const FLOW = [
        self::NEW, self::PENDING_APPROVAL, self::APPROVED, self::WORK_ORDER_CREATED, self::MATERIAL_ISSUED,
        self::IN_PRODUCTION, self::UNDER_QC, self::REWORK_REQUIRED, self::PRODUCTION_COMPLETED,
        self::READY_FOR_DISPATCH, self::DISPATCHED, self::DELIVERED, self::CLOSED,
    ];

    protected $attributes = ['status' => self::NEW, 'source' => 'staff', 'advance_amount' => '0', 'payment_status' => 'unpaid'];

    protected $fillable = ['order_no', 'retailer_id', 'source', 'due_date', 'remarks', 'estimated_value', 'status'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'confirmed_at' => 'datetime',
            'approved_at' => 'datetime',
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
            'estimated_value' => 'decimal:2',
            'advance_amount' => 'decimal:2',
        ];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::CANCELLED, self::CLOSED], true);
    }
}
