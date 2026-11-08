<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One process step given to one kaarigar; gold issued and received against it. */
class JobCard extends Model
{
    use Audited, BelongsToTenant;

    public const OPEN = 'open';
    public const CLOSED = 'closed';

    protected $attributes = ['status' => self::OPEN];

    protected $fillable = [
        'card_no', 'kaarigar_id', 'process_id', 'karat_id', 'wo_step_id', 'design_id',
        'description', 'pieces', 'due_date', 'kaarigar_status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
            'excess_resolved_at' => 'datetime',
            'issued_fine' => 'decimal:3', 'returned_fine' => 'decimal:3', 'scrap_fine' => 'decimal:3',
            'loss_fine' => 'decimal:3', 'allowed_loss_fine' => 'decimal:3', 'excess_loss_fine' => 'decimal:3',
            'allowed_wastage_pct' => 'decimal:3',
        ];
    }

    public function kaarigar(): BelongsTo
    {
        return $this->belongsTo(Kaarigar::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function karat(): BelongsTo
    {
        return $this->belongsTo(Karat::class);
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function woStep(): BelongsTo
    {
        return $this->belongsTo(WoStep::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MetalTransaction::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
