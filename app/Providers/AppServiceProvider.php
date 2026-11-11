<?php

namespace App\Providers;

use App\Services\Push\PushSender;
use App\Services\Push\WebPushSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\Msg91SmsSender;
use App\Services\Sms\SmsSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant per request / job; reset between Octane requests and queued jobs.
        $this->app->scoped(TenantContext::class);

        $this->app->bind(PushSender::class, WebPushSender::class);

        $this->app->bind(SmsSender::class, fn () => match (config('tenancy.sms_driver')) {
            'msg91' => new Msg91SmsSender((string) config('services.msg91.key'), (string) config('services.msg91.otp_template_id')),
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        /** tenant_id + created_by on every tenant-owned table. */
        Blueprint::macro('tenantColumns', function () {
            /** @var Blueprint $this */
            $this->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $this->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Stamp tenant and IP on every audit row.
        Activity::creating(function (Activity $activity) {
            $activity->tenant_id ??= app(TenantContext::class)->id();
            $activity->ip_address ??= $this->app->runningInConsole() ? null : request()->ip();
        });

        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(3)->by('otp-phone:'.$request->input('phone')),
            Limit::perMinute(10)->by('otp-ip:'.$request->ip()),
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login:'.strtolower((string) ($request->input('email') ?? $request->input('phone'))).'|'.$request->ip()),
        ]);

        RateLimiter::for('signup', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
    }
}
