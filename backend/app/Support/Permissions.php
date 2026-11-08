<?php

namespace App\Support;

/**
 * Permission names (global) and the default roles each tenant is provisioned with.
 * Roles are per tenant (spatie "teams" on tenant_id), so tenants can add custom roles.
 */
final class Permissions
{
    public const SETTINGS_MANAGE = 'settings.manage';      // karats, processes, wastage, routes, rates, branding
    public const USERS_MANAGE = 'users.manage';
    public const KAARIGARS_VIEW = 'kaarigars.view';
    public const KAARIGARS_MANAGE = 'kaarigars.manage';
    public const DESIGNS_MANAGE = 'designs.manage';
    public const RETAILERS_MANAGE = 'retailers.manage';
    public const METAL_ISSUE = 'metal.issue';
    public const METAL_RECEIVE = 'metal.receive';
    public const METAL_VAULT = 'metal.vault';              // melting, refining, transfers
    public const METAL_APPROVE_LOSS = 'metal.approve_loss';
    public const METAL_REVERSE = 'metal.reverse';
    public const ORDERS_MANAGE = 'orders.manage';
    public const PRODUCTION_MANAGE = 'production.manage';
    public const ACCOUNTS_MANAGE = 'accounts.manage';
    public const REPORTS_VIEW = 'reports.view';

    public const GUARD = 'staff';

    public const ROLE_OWNER = 'owner';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SETTINGS_MANAGE, self::USERS_MANAGE, self::KAARIGARS_VIEW, self::KAARIGARS_MANAGE,
            self::DESIGNS_MANAGE, self::RETAILERS_MANAGE, self::METAL_ISSUE, self::METAL_RECEIVE,
            self::METAL_VAULT, self::METAL_APPROVE_LOSS, self::METAL_REVERSE, self::ORDERS_MANAGE,
            self::PRODUCTION_MANAGE, self::ACCOUNTS_MANAGE, self::REPORTS_VIEW,
        ];
    }

    /** @return array<string, list<string>> */
    public static function defaultRoles(): array
    {
        return [
            self::ROLE_OWNER => self::all(),
            'metal_manager' => [
                self::KAARIGARS_VIEW, self::KAARIGARS_MANAGE, self::METAL_ISSUE, self::METAL_RECEIVE,
                self::METAL_VAULT, self::REPORTS_VIEW,
            ],
            'production_manager' => [
                self::KAARIGARS_VIEW, self::ORDERS_MANAGE, self::PRODUCTION_MANAGE,
            ],
            'sales' => [
                self::ORDERS_MANAGE, self::RETAILERS_MANAGE, self::DESIGNS_MANAGE,
            ],
            'accounts' => [
                self::ACCOUNTS_MANAGE, self::RETAILERS_MANAGE, self::REPORTS_VIEW,
            ],
        ];
    }
}
