<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One production stage given to one kaarigar; gold issued, handed over and
 * received against it. A child card is work a head kaarigar passed to a
 * sub-kaarigar (or the factory passed between kaarigars) from a parent card.
 */
class JobCard extends Model
{
    use Audited, BelongsToTenant;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const LOSS_PENDING = 'pending';

    public const LOSS_APPROVED = 'approved';

    /** Why a difference between gold in and gold out was accepted (blueprint §3 control rule). */
    public const LOSS_REASONS = [
        'process_loss' => 'Normal process loss',
        'dust_not_recovered' => 'Dust / filings not yet recovered',
        'stone_setting_loss' => 'Stone setting loss',
        'polishing_loss' => 'Polishing loss',
        'weighing_difference' => 'Weighing / purity difference',
        'other' => 'Other (explain in the note)',
    ];

    protected $attributes = ['status' => self::OPEN];

    protected $fillable = [
        'card_no', 'kaarigar_id', 'process_id', 'karat_id', 'wo_step_id', 'parent_job_card_id', 'design_id',
        'description', 'pieces', 'due_date', 'kaarigar_status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
            'excess_resolved_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'loss_approved_at' => 'datetime',
            'issued_fine' => 'decimal:3', 'returned_fine' => 'decimal:3', 'scrap_fine' => 'decimal:3',
            'handed_in_fine' => 'decimal:3', 'handed_out_fine' => 'decimal:3',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_job_card_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_job_card_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MetalTransaction::class);
    }

    /** Progress photos from the kaarigar and stage attachments from staff. */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function lossApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loss_approved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function lossPending(): bool
    {
        return $this->loss_status === self::LOSS_PENDING;
    }
}
