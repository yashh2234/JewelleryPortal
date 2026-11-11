<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Response;

/**
 * Serves the built React app (public/app.html) for every page URL of a workspace,
 * with the tenant's branding injected so the first paint already shows their name
 * and colour. Assets, sw.js and icons are real files served by Apache directly.
 */
class SpaController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        $page = public_path('app.html');

        if (! is_file($page)) {
            return response('The app has not been built yet. Run `npm run build` in /frontend.', 503);
        }

        $tenant = $context->get();
        $boot = json_encode(
            ['tenant' => $tenant->branding()],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );

        $html = str_replace('</head>', "<script>window.__BOOT__ = {$boot};</script>\n</head>", file_get_contents($page));

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache');
    }
}
