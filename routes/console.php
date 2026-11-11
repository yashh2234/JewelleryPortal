<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
| cPanel runs `php artisan schedule:run` every minute from cron. There is no resident
| queue worker on shared hosting, so the scheduler drains the database queue each
| minute and exits before the next run.
*/
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Stored Idempotency-Key responses only need to outlive client retries.
Schedule::call(fn () => DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(2))->delete())
    ->daily()
    ->name('prune-idempotency-keys');

// Nightly: rebuild cached gold balances from ledger lines and check every ledger sums to zero.
Schedule::command('metal:rebuild-balances')->dailyAt('02:30');

// Morning: delayed production, overdue orders, unacknowledged gold issues (in-app + push).
Schedule::command('erp:daily-alerts')->dailyAt('08:30');

// Read in-app notifications older than 90 days are cleared.
Schedule::call(fn () => DB::table('app_notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(90))->delete())
    ->daily()
    ->name('prune-read-notifications');

// The activity log is never pruned: it is the audit trail for gold and accounts.
