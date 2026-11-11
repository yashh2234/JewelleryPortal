<?php

namespace App\Support;

use App\Models\Design;
use App\Models\Retailer;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/** Plan tier limits (staff users, active retailers, catalogue designs). null = unlimited. */
final class PlanLimits
{
    private const LIMITS = [
        'users' => ['max_users', 'staff users'],
        'retailers' => ['max_retailers', 'active retailers'],
        'designs' => ['max_designs', 'designs'],
    ];

    public static function ensureRoomFor(string $what): void
    {
        [$column, $label] = self::LIMITS[$what];
        $max = app(TenantContext::class)->get()?->plan?->{$column};

        if ($max === null) {
            return;
        }

        $used = match ($what) {
            'users' => User::where('is_active', true)->count(),
            'retailers' => Retailer::where('status', Retailer::STATUS_ACTIVE)->count(),
            'designs' => Design::count(),
        };

        if ($used >= $max) {
            abort(422, "Your plan allows {$max} {$label}. Upgrade the plan to add more.");
        }
    }
}
