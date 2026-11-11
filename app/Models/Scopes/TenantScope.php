<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isCentral()) {
            return;
        }

        // No tenant resolved → match nothing (fail closed).
        $builder->where($model->qualifyColumn('tenant_id'), $context->id() ?? 0);
    }
}
