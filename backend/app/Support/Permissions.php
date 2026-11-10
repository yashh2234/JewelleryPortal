<?php

namespace App\Support;

/**
 * Permission names (global) and the default roles each tenant is provisioned with.
 * Roles are per tenant (spatie "teams" on tenant_id), so tenants can add custom roles.
 *
 * Spec §13 roles map onto staff roles here; Super Admin is the platform admin
 * (/admin), Karigar and Retailer are their own apps with their own logins.
 */
final class Permissions
{
    public const SETTINGS_MANAGE = 'settings.manage';      // karats, processes, wastage, routes, rates, branding
    public const USERS_MANAGE = 'users.manage';
    public const KAARIGARS_VIEW = 'kaarigars.view';
    public const KAARIGARS_MANAGE = 'kaarigars.manage';
    public const DESIGNS_MANAGE = 'designs.manage';
    public const RETAILERS_MANAGE = 'retailers.manage';    // CRM: retailers, notes, queries
    public const METAL_ISSUE = 'metal.issue';
    public const METAL_RECEIVE = 'metal.receive';
    public const METAL_VAULT = 'metal.vault';              // melting, refining, transfers
    public const METAL_APPROVE_LOSS = 'metal.approve_loss'; // also: override the gold-issue rules
    public const METAL_REVERSE = 'metal.reverse';
    public const ORDERS_MANAGE = 'orders.manage';          // enter orders, send for approval, hold
    public const ORDERS_APPROVE = 'orders.approve';        // approve / cancel / close orders
    public const PRODUCTION_MANAGE = 'production.manage';
    public const QC_MANAGE = 'qc.manage';                  // quality check, rework
    public const DISPATCH_MANAGE = 'dispatch.manage';      // packing, challans, shipments
    public const ACCOUNTS_MANAGE = 'accounts.manage';
    public const REPORTS_VIEW = 'reports.view';
    public const AUDIT_VIEW = 'audit.view';

    public const GUARD = 'staff';

    public const ROLE_OWNER = 'owner';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SETTINGS_MANAGE, self::USERS_MANAGE, self::KAARIGARS_VIEW, self::KAARIGARS_MANAGE,
            self::DESIGNS_MANAGE, self::RETAILERS_MANAGE, self::METAL_ISSUE, self::METAL_RECEIVE,
            self::METAL_VAULT, self::METAL_APPROVE_LOSS, self::METAL_REVERSE, self::ORDERS_MANAGE,
            self::ORDERS_APPROVE, self::PRODUCTION_MANAGE, self::QC_MANAGE, self::DISPATCH_MANAGE,
            self::ACCOUNTS_MANAGE, self::REPORTS_VIEW, self::AUDIT_VIEW,
        ];
    }

    /** @return array<string, list<string>> */
    public static function defaultRoles(): array
    {
        return [
            // Business Owner: everything.
            self::ROLE_OWNER => self::all(),
            'sales_manager' => [
                self::RETAILERS_MANAGE, self::ORDERS_MANAGE, self::DESIGNS_MANAGE, self::REPORTS_VIEW,
            ],
            'order_manager' => [
                self::ORDERS_MANAGE, self::ORDERS_APPROVE, self::PRODUCTION_MANAGE, self::RETAILERS_MANAGE,
            ],
            'production_manager' => [
                self::KAARIGARS_VIEW, self::KAARIGARS_MANAGE, self::PRODUCTION_MANAGE, self::METAL_ISSUE, self::METAL_RECEIVE,
            ],
            'inventory_manager' => [
                self::KAARIGARS_VIEW, self::METAL_ISSUE, self::METAL_RECEIVE, self::METAL_VAULT, self::REPORTS_VIEW,
            ],
            'accountant' => [
                self::ACCOUNTS_MANAGE, self::RETAILERS_MANAGE, self::REPORTS_VIEW,
            ],
            'quality_manager' => [
                self::QC_MANAGE,
            ],
            'dispatch_manager' => [
                self::DISPATCH_MANAGE,
            ],
            // Earlier presets, kept so existing users keep working.
            'metal_manager' => [
                self::KAARIGARS_VIEW, self::KAARIGARS_MANAGE, self::METAL_ISSUE, self::METAL_RECEIVE,
                self::METAL_VAULT, self::REPORTS_VIEW,
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
