<?php

use App\Http\Controllers\Web\ManifestController;
use App\Http\Controllers\Web\SpaController;
use Illuminate\Support\Facades\Route;

/*
| Pages. The React app lives at the root of each workspace: acme.yourapp.in/.
| Laravel serves public/app.html for every app URL (with the tenant injected);
| real files (assets, sw.js, icons) are served by Apache straight from /public.
*/

// ── Central domain: landing page and the super-admin console ────────────────
Route::domain(config('tenancy.central_domain'))->group(function () {
    Route::get('/', fn () => view('welcome'));

    Route::get('/admin/{path?}', function () {
        $page = public_path('app.html');
        abort_unless(is_file($page), 503, 'Front end not built. Run `npm run build` in /frontend.');

        return response(file_get_contents($page))->header('Content-Type', 'text/html; charset=UTF-8');
    })->where('path', '.*');
});

// ── Workspaces ───────────────────────────────────────────────────────────────
Route::middleware('tenant')->group(function () {
    // QR on a kaarigar's paper slip: read-only statement for the last 60 days, no login.
    Route::get('/khata/{token}', function (string $token, App\Services\Metal\Statements $statements, App\Support\Tenancy\TenantContext $ctx) {
        $kaarigar = App\Models\Kaarigar::where('slip_token', $token)->where('is_active', true)->firstOrFail();
        $from = today()->subDays(60)->toDateString();

        return view('pdf.kaarigar-statement', [
            'tenant' => $ctx->get(), 'kaarigar' => $kaarigar, 'from' => $from, 'to' => null,
            'statement' => $statements->kaarigar($kaarigar, $from),
        ]);
    })->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:30,1');

    Route::get('/manifest.webmanifest', ManifestController::class);

    // Every other page URL is the app (login, /staff/…, /portal/…, /kaarigar/…).
    Route::get('/{path?}', SpaController::class)
        ->where('path', '^(?!api/|sanctum/|storage/|up$).*$')
        ->name('spa');
});
