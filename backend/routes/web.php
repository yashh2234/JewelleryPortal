<?php

use App\Http\Controllers\Web\ManifestController;
use App\Http\Controllers\Web\SpaController;
use Illuminate\Support\Facades\Route;

// Central domain landing page (marketing / signup lives here later).
Route::get('/', function () {
    $host = request()->getHost();
    $central = config('tenancy.central_domain');

    // On a tenant subdomain, the root opens the staff app.
    return $host !== $central && str_ends_with($host, '.'.$central)
        ? redirect('/app/')
        : view('welcome');
});

// Super-admin console: same built app, but on the central domain with no workspace.
Route::get('/app/admin/{path?}', function () {
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 503, 'Front end not built.');

    return response(file_get_contents($index))->header('Content-Type', 'text/html; charset=UTF-8');
})->where('path', '.*');

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

    Route::get('/manifest/{app}.webmanifest', ManifestController::class)
        ->whereIn('app', SpaController::APPS);

    foreach (SpaController::APPS as $app) {
        Route::get("/{$app}/{path?}", SpaController::class)
            ->where('path', '.*')
            ->defaults('app', $app)
            ->name("spa.{$app}");
    }
});
