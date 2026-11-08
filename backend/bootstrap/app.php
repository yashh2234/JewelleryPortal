<?php

use App\Http\Middleware\EnsureTenantMember;
use App\Http\Middleware\EnsureWorkspaceWritable;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth: session + CSRF on API calls from our own front ends.
        $middleware->statefulApi();

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.member' => EnsureTenantMember::class,
            'workspace.writable' => EnsureWorkspaceWritable::class,
            'idempotent' => IdempotentRequest::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);

        // The tenant must be known before any guard looks up a user.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResolveTenant::class);

        // API clients get JSON 401s, not a redirect to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
