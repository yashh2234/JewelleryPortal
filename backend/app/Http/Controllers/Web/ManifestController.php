<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Per-tenant web manifest, so each manufacturer's name and colour show on the
 * installed icon. One app for everyone: staff, kaarigars and retailers install the
 * same thing and land on their own dashboard after login.
 */
class ManifestController extends Controller
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $tenant = $context->get();

        $icons = $tenant->logo_url
            ? [['src' => $tenant->logo_url, 'sizes' => '512x512', 'type' => 'image/webp', 'purpose' => 'any']]
            : [
                ['src' => '/pwa-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/pwa-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/pwa-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ];

        return response()->json([
            'id' => '/',
            'name' => $tenant->name,
            'short_name' => mb_substr($tenant->name, 0, 12),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => $tenant->primary_color,
            'icons' => $icons,
        ])->header('Content-Type', 'application/manifest+json');
    }
}
