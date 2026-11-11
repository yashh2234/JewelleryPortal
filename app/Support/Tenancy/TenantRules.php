<?php

namespace App\Support\Tenancy;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules that stay inside the current tenant. Plain exists/unique rules
 * query the table directly (no global scope), so always use these instead.
 */
final class TenantRules
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return (new Exists($table, $column))->where('tenant_id', self::tenantId());
    }

    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return (new Unique($table, $column))->where('tenant_id', self::tenantId());
    }

    private static function tenantId(): int
    {
        return app(TenantContext::class)->id() ?? 0;
    }
}
