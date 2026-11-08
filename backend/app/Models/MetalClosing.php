<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MetalClosing extends Model
{
    use BelongsToTenant;

    protected $fillable = ['date', 'system_vault_fine', 'physical_vault_fine', 'difference_fine', 'summary', 'remarks'];

    protected function casts(): array
    {
        return [
            'system_vault_fine' => 'decimal:3',
            'physical_vault_fine' => 'decimal:3',
            'difference_fine' => 'decimal:3',
            'summary' => 'array',
        ];
    }
}
