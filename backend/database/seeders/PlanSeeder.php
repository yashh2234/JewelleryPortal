<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** Placeholder tiers. Real prices get set after talking to the pilot manufacturers. */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'trial', 'name' => 'Trial', 'max_users' => 5, 'max_retailers' => 25, 'max_designs' => 200, 'price' => 0],
            ['code' => 'starter', 'name' => 'Starter', 'max_users' => 5, 'max_retailers' => 50, 'max_designs' => 500, 'price' => 0],
            ['code' => 'growth', 'name' => 'Growth', 'max_users' => 15, 'max_retailers' => 250, 'max_designs' => 3000, 'price' => 0],
            ['code' => 'pro', 'name' => 'Pro', 'max_users' => null, 'max_retailers' => null, 'max_designs' => null, 'price' => 0],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan);
        }
    }
}
