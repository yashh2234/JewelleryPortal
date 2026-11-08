<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * For every tenant-owned model: adds the tenant global scope, fills tenant_id and
 * created_by on create, and refuses to move a row to another tenant.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (empty($model->tenant_id)) {
                $model->tenant_id = $context->id()
                    ?? throw new LogicException('Cannot create '.static::class.' without a tenant.');
            } elseif ($context->check() && (int) $model->tenant_id !== $context->id()) {
                throw new LogicException('Cannot create '.static::class.' for another tenant.');
            }

            // Child rows (e.g. ledger lines) have no created_by; their header carries it.
            if (! property_exists($model, 'withoutCreatedBy') && empty($model->created_by)) {
                $model->created_by = Auth::guard('staff')->id();
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id cannot be changed.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
