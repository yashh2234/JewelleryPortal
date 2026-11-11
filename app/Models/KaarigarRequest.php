<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Something a kaarigar asked for in the app. Becomes a ledger posting only when the store approves. */
class KaarigarRequest extends Model
{
    use Audited, BelongsToTenant;

    public const DISPUTE = 'dispute';

    public const RETURN = 'return';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $attributes = ['status' => self::PENDING];

    protected $fillable = ['kaarigar_id', 'kaarigar_user_id', 'type', 'job_card_id', 'metal_transaction_id', 'data', 'client_key'];

    protected function casts(): array
    {
        return ['data' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function kaarigar(): BelongsTo
    {
        return $this->belongsTo(Kaarigar::class);
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(MetalTransaction::class, 'metal_transaction_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
