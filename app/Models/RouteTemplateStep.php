<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteTemplateStep extends Model
{
    use BelongsToTenant;

    protected $fillable = ['route_template_id', 'process_id', 'sequence'];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }
}
