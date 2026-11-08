<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for the request:
 *   1. subdomain of the central domain: acme.yourapp.in → "acme"
 *   2. otherwise (central domain, localhost, Vite dev proxy) the X-Tenant header,
 *      when tenancy.allow_header is on. Path-based tenants can use this later.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->set(null);

        $slug = $this->subdomain($request->getHost())
            ?? (config('tenancy.allow_header') ? $request->header('X-Tenant') : null);

        $tenant = $slug ? Tenant::where('subdomain', strtolower($slug))->first() : null;

        if (! $tenant) {
            return $this->fail($request, 404, 'Unknown workspace.');
        }

        if (! $tenant->isUsable()) {
            return $this->fail($request, 403, 'This workspace is suspended. Please contact support.');
        }

        $this->context->set($tenant);

        return $next($request);
    }

    private function subdomain(string $host): ?string
    {
        $central = config('tenancy.central_domain');
        $suffix = '.'.$central;

        if (! $central || ! str_ends_with($host, $suffix)) {
            return null;
        }

        $sub = substr($host, 0, -strlen($suffix));

        return ($sub !== '' && ! str_contains($sub, '.')) ? $sub : null;
    }

    private function fail(Request $request, int $status, string $message): Response
    {
        return $request->expectsJson() || $request->is('api/*')
            ? response()->json(['message' => $message], $status)
            : response($message, $status);
    }
}
