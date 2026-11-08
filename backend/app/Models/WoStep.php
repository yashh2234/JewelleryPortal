<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WoStep extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const DONE = 'done';
    public const SKIPPED = 'skipped';

    protected $attributes = ['status' => self::PENDING];

    protected $fillable = ['work_order_id', 'process_id', 'sequence', 'status'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function jobCards(): HasMany
    {
        return $this->hasMany(JobCard::class);
    }
}
