<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class WorkOrder extends Model
{
    use Audited, BelongsToTenant;

    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    protected $attributes = ['status' => self::OPEN, 'priority' => 'normal'];

    protected $fillable = [
        'wo_no', 'design_id', 'karat_id', 'qty', 'sizes', 'target_wt_min', 'target_wt_max',
        'due_date', 'priority', 'rework_of_id', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'sizes' => 'array',
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
            'target_wt_min' => 'decimal:3',
            'target_wt_max' => 'decimal:3',
        ];
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function karat(): BelongsTo
    {
        return $this->belongsTo(Karat::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WoStep::class)->orderBy('sequence');
    }

    public function jobCards(): HasManyThrough
    {
        return $this->hasManyThrough(JobCard::class, WoStep::class);
    }

    public function orderLines(): BelongsToMany
    {
        return $this->belongsToMany(OrderLine::class, 'wo_order_lines')->withPivot('qty');
    }

    public function finishedItems(): HasMany
    {
        return $this->hasMany(FinishedItem::class);
    }

    /** The first step that is not done yet. */
    public function currentStep(): ?WoStep
    {
        return $this->steps->first(fn (WoStep $s) => ! in_array($s->status, [WoStep::DONE, WoStep::SKIPPED], true));
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::OPEN, self::IN_PROGRESS], true);
    }
}
