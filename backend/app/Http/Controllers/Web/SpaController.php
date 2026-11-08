<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the built React app (public/app): staff, kaarigar and retailer areas behind one login. Real files
 * (JS, CSS, sw.js) are served by Apache straight from /public; every other path under
 * the app's scope falls through to here and gets index.html with the tenant injected.
 */
class SpaController extends Controller
{
    public const APPS = ['app'];

    public function __invoke(Request $request, TenantContext $context): Response
    {
        // Read by name: route parameters are passed positionally, and {path} varies.
        $app = $request->route('app');
        abort_unless(in_array($app, self::APPS, true), 404);

        $index = public_path("{$app}/index.html");

        if (! is_file($index)) {
            return response("The {$app} front end has not been built yet. Run `npm run build` in /frontend.", 503);
        }

        $boot = json_encode([
            'tenant' => $context->get()->branding(),
            'app' => $app,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        $tenant = $context->get();
        $html = str_replace(
            ['</head>', '__TENANT_NAME__', '__TENANT_COLOR__'],
            ["<script>window.__BOOT__ = {$boot};</script>\n</head>", e($tenant->name), e($tenant->primary_color)],
            file_get_contents($index),
        );

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache');
    }
}
