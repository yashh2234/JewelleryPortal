<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CRM communication history: a call, visit, WhatsApp chat or note about a retailer. */
class CrmNote extends Model
{
    use Audited, BelongsToTenant;

    public const CHANNELS = ['call', 'visit', 'whatsapp', 'email', 'note'];

    protected $fillable = ['retailer_id', 'user_id', 'channel', 'body', 'follow_up_on'];

    protected function casts(): array
    {
        return ['follow_up_on' => 'date:Y-m-d'];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
