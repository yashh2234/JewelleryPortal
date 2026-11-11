<?php

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PushSubscriptionController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Kaarigar\AppController as KaarigarApp;
use App\Http\Controllers\Kaarigar\AuthController as KaarigarAuth;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Platform\AdminController;
use App\Http\Controllers\Platform\SignupController;
use App\Http\Controllers\Portal;
use App\Http\Controllers\Portal\AuthController as PortalAuth;
use App\Http\Controllers\Staff;
use App\Http\Controllers\Staff\AuthController as StaffAuth;
use App\Models\User;
use App\Support\Permissions as P;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

/*
| Route groups (all under /api):
|   /api/platform/*      central: manufacturer signup
|   /api/tenant          public branding for the resolved tenant
|   /api/auth/*          the one login for everyone (staff, retailer, kaarigar)
|   /api/v1/*            staff app        (guard: staff)
|   /api/portal/v1/*     retailer portal  (guard: retailer)
|   /api/kaarigar/v1/*   kaarigar app     (guard: kaarigar)
|
| Every authenticated write accepts an Idempotency-Key header.
*/

Route::prefix('platform')->group(function () {
    Route::post('signup', [SignupController::class, 'store'])->middleware('throttle:signup');

    // Super-admin (you). Create one with: php artisan platform:admin you@example.com
    Route::prefix('admin')->group(function () {
        Route::post('login', [AdminController::class, 'login'])->middleware('throttle:login');

        Route::middleware('auth:platform')->group(function () {
            Route::get('me', [AdminController::class, 'me']);
            Route::post('logout', [AdminController::class, 'logout']);
            Route::get('tenants', [AdminController::class, 'tenants']);
            Route::patch('tenants/{tenant}', [AdminController::class, 'updateTenant']);
            Route::get('plans', [AdminController::class, 'plans']);
        });
    });
});

Route::middleware('tenant')->group(function () {
    Route::get('tenant', fn (TenantContext $ctx) => response()->json(['data' => $ctx->get()->branding()]));

    // ── One login: the server decides staff / retailer / kaarigar ────────────
    Route::prefix('auth')->group(function () {
        Route::post('login', [SessionController::class, 'login'])->middleware('throttle:login');
        Route::post('otp', [SessionController::class, 'requestOtp'])->middleware('throttle:otp');
        Route::post('otp/verify', [SessionController::class, 'verifyOtp'])->middleware('throttle:login');
        Route::get('me', [SessionController::class, 'me']);
        Route::post('logout', [SessionController::class, 'logout']);
        Route::post('forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:otp');
        Route::post('reset', [PasswordResetController::class, 'reset'])->middleware('throttle:login');
    });

    // Web Push for whoever is signed in on this device.
    Route::get('push/key', [PushSubscriptionController::class, 'key']);
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy']);

    // ── Staff (manufacturer app) ─────────────────────────────────────────────
    Route::prefix('v1')->group(function () {
        Route::middleware(['auth:staff', 'tenant.member:staff', 'workspace.writable', 'idempotent'])->group(function () {
            Route::get('auth/me', [StaffAuth::class, 'me']);
            Route::get('dashboard', Staff\DashboardController::class);
            Route::get('notifications', [NotificationController::class, 'index'])->defaults('guard', 'staff');
            Route::post('notifications/read', [NotificationController::class, 'read'])->defaults('guard', 'staff');

            // Reference data any staff user can read (pickers, dropdowns).
            Route::get('karats', [Staff\KaratController::class, 'index']);
            // Names only, for "assigned to" / "salesperson" pickers.
            Route::get('staff-directory', fn () => response()->json(['data' => User::where('is_active', true)->orderBy('name')->get(['id', 'name'])]));
            Route::get('processes', [Staff\ProcessController::class, 'index']);
            Route::get('categories', [Staff\CategoryController::class, 'index']);
            Route::get('collections', [Staff\CollectionController::class, 'index']);
            Route::get('making-slabs', [Staff\MakingSlabController::class, 'index']);
            Route::get('route-templates', [Staff\RouteTemplateController::class, 'index']);
            Route::get('gold-rates', [Staff\GoldRateController::class, 'index']);
            Route::get('designs', [Staff\DesignController::class, 'index']);
            Route::get('designs/{design}', [Staff\DesignController::class, 'show']);

            Route::middleware('permission:'.P::SETTINGS_MANAGE)->group(function () {
                Route::get('settings/tenant', [Staff\TenantSettingsController::class, 'show']);
                Route::post('settings/tenant', [Staff\TenantSettingsController::class, 'update']); // multipart (logo)
                Route::apiResource('karats', Staff\KaratController::class)->only(['store', 'update']);
                Route::apiResource('processes', Staff\ProcessController::class)->only(['store', 'update']);
                Route::put('processes/{process}/wastage-rules', [Staff\ProcessController::class, 'syncWastageRules']);
                Route::apiResource('route-templates', Staff\RouteTemplateController::class)->only(['store', 'update', 'destroy']);
                Route::apiResource('making-slabs', Staff\MakingSlabController::class)->only(['store', 'update', 'destroy']);
                Route::post('gold-rates', [Staff\GoldRateController::class, 'store']);
            });

            Route::middleware('permission:'.P::DESIGNS_MANAGE)->group(function () {
                Route::apiResource('categories', Staff\CategoryController::class)->only(['store', 'update', 'destroy']);
                Route::apiResource('collections', Staff\CollectionController::class)->only(['store', 'update', 'destroy']);
                Route::apiResource('designs', Staff\DesignController::class)->only(['store', 'update', 'destroy']);
                Route::post('designs/{design}/images', [Staff\DesignImageController::class, 'store']);
                Route::post('designs/import', [Staff\DesignImportController::class, 'csv']);
                Route::post('designs/images/bulk', [Staff\DesignImportController::class, 'images']);
                Route::delete('designs/{design}/images/{image}', [Staff\DesignImageController::class, 'destroy']);
            });

            Route::middleware('permission:'.P::KAARIGARS_VIEW.'|'.P::KAARIGARS_MANAGE)->group(function () {
                Route::get('kaarigars', [Staff\KaarigarController::class, 'index']);
                Route::get('kaarigars/{kaarigar}', [Staff\KaarigarController::class, 'show']);
            });

            Route::middleware('permission:'.P::KAARIGARS_MANAGE)->group(function () {
                Route::post('kaarigars', [Staff\KaarigarController::class, 'store']);
                Route::patch('kaarigars/{kaarigar}', [Staff\KaarigarController::class, 'update']);
                Route::post('kaarigars/{kaarigar}/app-users', [Staff\KaarigarController::class, 'addAppUser']);
                Route::patch('kaarigars/{kaarigar}/app-users/{appUser}', [Staff\KaarigarController::class, 'updateAppUser']);
            });

            Route::middleware('permission:'.P::RETAILERS_MANAGE)->group(function () {
                Route::apiResource('retailers', Staff\RetailerController::class)->except(['destroy']);
                Route::post('retailers/{retailer}/approve', [Staff\RetailerController::class, 'approve']);
                Route::post('retailers/{retailer}/reject', [Staff\RetailerController::class, 'reject']);
                Route::post('retailers/{retailer}/suspend', [Staff\RetailerController::class, 'suspend']);
                Route::put('retailers/{retailer}/collections', [Staff\RetailerController::class, 'collections']);

                // CRM: profile, communication log, follow-ups, retailer queries.
                Route::get('crm/retailers/{retailer}', [Staff\CrmController::class, 'profile']);
                Route::post('crm/retailers/{retailer}/notes', [Staff\CrmController::class, 'addNote']);
                Route::delete('crm/retailers/{retailer}/notes/{note}', [Staff\CrmController::class, 'deleteNote']);
                Route::post('crm/retailers/{retailer}/notes/{note}/done', [Staff\CrmController::class, 'followUpDone']);
                Route::get('crm/follow-ups', [Staff\CrmController::class, 'followUps']);
                Route::get('queries', [Staff\QueryController::class, 'index']);
                Route::get('queries/{query}', [Staff\QueryController::class, 'show']);
                Route::post('queries/{query}/reply', [Staff\QueryController::class, 'reply']);
                Route::post('queries/{query}/status', [Staff\QueryController::class, 'setStatus']);
            });

            // ── Gold ledger (Module 1) ───────────────────────────────────────
            Route::middleware('permission:'.P::KAARIGARS_VIEW.'|'.P::METAL_ISSUE.'|'.P::REPORTS_VIEW)->group(function () {
                Route::get('metal/summary', [Staff\MetalController::class, 'summary']);
                Route::get('metal/stock', [Staff\MetalController::class, 'stock']);
                Route::get('metal/balances', [Staff\MetalController::class, 'balances']);
                Route::get('metal/transactions', [Staff\MetalController::class, 'transactions']);
                Route::get('metal/transactions/{transaction}', [Staff\MetalController::class, 'show']);
                Route::get('metal/transactions/{transaction}/slip', [Staff\MetalController::class, 'slip']);
                Route::get('job-cards', [Staff\JobCardController::class, 'index']);
                Route::get('job-cards/{jobCard}', [Staff\JobCardController::class, 'show']);
                Route::get('kaarigars/{kaarigar}/ledger', [Staff\KaarigarLedgerController::class, 'show']);
                Route::get('kaarigars/{kaarigar}/ledger.pdf', [Staff\KaarigarLedgerController::class, 'pdf']);
            });

            Route::middleware('permission:'.P::METAL_ISSUE)->group(function () {
                Route::post('job-cards', [Staff\JobCardController::class, 'store']);
                Route::post('metal/issue', [Staff\MetalController::class, 'issue']);
                Route::get('metal/issue-check', [Staff\MetalController::class, 'issueCheck']);
                Route::post('metal/transactions/{transaction}/acknowledge', [Staff\MetalController::class, 'acknowledge']);
                // Kaarigar → kaarigar custody (blueprint §2).
                Route::post('job-cards/{jobCard}/allocate', [Staff\JobCardController::class, 'allocate']);
                Route::post('job-cards/{jobCard}/hand-back', [Staff\JobCardController::class, 'handBack']);
                Route::post('job-cards/{jobCard}/attachments', [Staff\JobCardController::class, 'attach']);
            });

            Route::middleware('permission:'.P::METAL_RECEIVE)->group(function () {
                Route::post('metal/receive', [Staff\MetalController::class, 'receive']);
                Route::post('job-cards/{jobCard}/close', [Staff\JobCardController::class, 'close']);
                Route::get('kaarigar-requests', [Staff\KaarigarRequestController::class, 'index']);
                Route::post('kaarigar-requests/{kaarigarRequest}/approve', [Staff\KaarigarRequestController::class, 'approve']);
                Route::post('kaarigar-requests/{kaarigarRequest}/reject', [Staff\KaarigarRequestController::class, 'reject']);
            });

            Route::middleware('permission:'.P::METAL_VAULT)->group(function () {
                Route::post('metal/purchase', [Staff\MetalController::class, 'purchase']);
                Route::post('metal/deposit', [Staff\MetalController::class, 'deposit']);
                Route::post('metal/melt', [Staff\MetalController::class, 'melt']);
                Route::post('metal/refine', [Staff\MetalController::class, 'refine']);
                Route::post('metal/transfer', [Staff\MetalController::class, 'transfer']);
            });

            Route::post('metal/transactions/{transaction}/reverse', [Staff\MetalController::class, 'reverse'])
                ->middleware('permission:'.P::METAL_REVERSE);
            Route::post('job-cards/{jobCard}/approve-loss', [Staff\JobCardController::class, 'approveLoss'])
                ->middleware('permission:'.P::METAL_APPROVE_LOSS);

            // ── Orders, production, QC, dispatch (Modules 2 & 6) ─────
            Route::middleware('permission:'.implode('|', [P::ORDERS_MANAGE, P::ORDERS_APPROVE, P::PRODUCTION_MANAGE, P::QC_MANAGE, P::DISPATCH_MANAGE, P::METAL_ISSUE, P::REPORTS_VIEW]))->group(function () {
                Route::get('orders', [Staff\OrderController::class, 'index']);
                Route::get('finished-items/{item}/history', [Staff\FinishedGoodsController::class, 'history']);
                Route::get('couriers', [Staff\FinishedGoodsController::class, 'couriers']);
                Route::get('dispatches/{dispatch}/documents/{attachment}', [Staff\FinishedGoodsController::class, 'document']);
                Route::get('orders/{order}', [Staff\OrderController::class, 'show']);
                Route::get('work-orders', [Staff\WorkOrderController::class, 'index']);
                Route::get('work-orders/board', [Staff\WorkOrderController::class, 'board']);
                Route::get('work-orders/{workOrder}', [Staff\WorkOrderController::class, 'show']);
                Route::get('finished-items', [Staff\FinishedGoodsController::class, 'items']);
                Route::get('dispatches', [Staff\FinishedGoodsController::class, 'dispatches']);
                Route::get('dispatches/{dispatch}/challan', [Staff\FinishedGoodsController::class, 'challan']);
            });

            Route::middleware('permission:'.P::ORDERS_MANAGE)->group(function () {
                Route::post('orders', [Staff\OrderController::class, 'store']);
                Route::post('orders/{order}/submit', [Staff\OrderController::class, 'submit']);
                Route::post('orders/{order}/hold', [Staff\OrderController::class, 'hold']);
                Route::post('orders/{order}/resume', [Staff\OrderController::class, 'resume']);
            });

            // Order manager: approve, cancel, close (spec §13).
            Route::middleware('permission:'.P::ORDERS_APPROVE)->group(function () {
                Route::post('orders/{order}/approve', [Staff\OrderController::class, 'approve']);
                Route::post('orders/{order}/confirm', [Staff\OrderController::class, 'confirm']);
                Route::post('orders/{order}/cancel', [Staff\OrderController::class, 'cancel']);
                Route::post('orders/{order}/close', [Staff\OrderController::class, 'close']);
            });

            Route::post('orders/{order}/advance', [Staff\OrderController::class, 'advance'])
                ->middleware('permission:'.P::ORDERS_MANAGE.'|'.P::ACCOUNTS_MANAGE);

            // Dispatch manager: packing, challans, shipment tracking.
            Route::middleware('permission:'.P::DISPATCH_MANAGE)->group(function () {
                Route::post('dispatches', [Staff\FinishedGoodsController::class, 'dispatch']);
                Route::patch('dispatches/{dispatch}/shipment', [Staff\FinishedGoodsController::class, 'updateShipment']);
                Route::post('dispatches/{dispatch}/documents', [Staff\FinishedGoodsController::class, 'uploadDocument']);
            });

            // Quality manager: QC and rework.
            Route::middleware('permission:'.P::QC_MANAGE)->group(function () {
                Route::post('finished-items/qc', [Staff\FinishedGoodsController::class, 'qc']);
                Route::post('finished-items/rework', [Staff\FinishedGoodsController::class, 'rework']);
            });

            Route::post('finished-items/location', [Staff\FinishedGoodsController::class, 'location'])
                ->middleware('permission:'.P::QC_MANAGE.'|'.P::DISPATCH_MANAGE.'|'.P::PRODUCTION_MANAGE);

            Route::middleware('permission:'.P::PRODUCTION_MANAGE)->group(function () {
                Route::post('orders/{order}/work-orders', [Staff\OrderController::class, 'workOrders']);
                Route::post('work-orders', [Staff\WorkOrderController::class, 'store']);
                Route::post('work-orders/{workOrder}/steps/{step}/advance', [Staff\WorkOrderController::class, 'advance']);
                Route::patch('work-orders/{workOrder}/steps/{step}', [Staff\WorkOrderController::class, 'updateStep']);
                Route::post('work-orders/{workOrder}/finish', [Staff\WorkOrderController::class, 'finish']);
                Route::post('work-orders/{workOrder}/complete', [Staff\WorkOrderController::class, 'complete']);
                Route::post('work-orders/{workOrder}/cancel', [Staff\WorkOrderController::class, 'cancel']);
            });

            // Issuing gold for a stage moves gold: needs the metal issue right.
            Route::post('work-orders/{workOrder}/issue', [Staff\WorkOrderController::class, 'issue'])
                ->middleware('permission:'.P::METAL_ISSUE);

            // ── Accounting (Module 4) ────────────────────────────────────────
            Route::middleware('permission:'.P::ACCOUNTS_MANAGE.'|'.P::REPORTS_VIEW)->group(function () {
                Route::get('accounts', [Staff\AccountingController::class, 'accounts']);
                Route::get('parties', [Staff\AccountingController::class, 'parties']);
                Route::get('parties/{party}/ledger', [Staff\AccountingController::class, 'partyLedger']);
                Route::get('vouchers', [Staff\AccountingController::class, 'index']);
                Route::get('vouchers/{voucher}', [Staff\AccountingController::class, 'show']);
                Route::get('invoices/{voucher}/pdf', [Staff\AccountingController::class, 'invoicePdf']);
                Route::get('tax-settings', [Staff\AccountingController::class, 'taxSettings']);
                Route::get('labour-rates', [Staff\AccountingController::class, 'labourRates']);
                Route::get('financial-years', [Staff\AccountingController::class, 'financialYears']);
                Route::get('accounting-reports/{name}', [Staff\AccountingController::class, 'report'])
                    ->whereIn('name', ['trial-balance', 'pnl', 'balance-sheet', 'outstanding', 'cash-book', 'gstr1', 'gstr3b', 'stock-valuation', 'daybook']);
            });

            Route::middleware('permission:'.P::ACCOUNTS_MANAGE)->group(function () {
                Route::post('accounts', [Staff\AccountingController::class, 'saveAccount']);
                Route::patch('accounts/{account}', [Staff\AccountingController::class, 'saveAccount']);
                Route::post('parties', [Staff\AccountingController::class, 'saveParty']);
                Route::patch('parties/{party}', [Staff\AccountingController::class, 'saveParty']);
                Route::post('vouchers/receipt', [Staff\AccountingController::class, 'receipt']);
                Route::post('vouchers/payment', [Staff\AccountingController::class, 'payment']);
                Route::post('vouchers/metal-receipt', [Staff\AccountingController::class, 'metal'])->defaults('direction', 'receipt');
                Route::post('vouchers/metal-payment', [Staff\AccountingController::class, 'metal'])->defaults('direction', 'payment');
                Route::post('vouchers/purchase', [Staff\AccountingController::class, 'purchase']);
                Route::post('vouchers/journal', [Staff\AccountingController::class, 'journal']);
                Route::post('vouchers/{voucher}/cancel', [Staff\AccountingController::class, 'cancel']);
                Route::post('rate-cuts', [Staff\AccountingController::class, 'rateCut']);
                Route::get('dispatches/{dispatch}/invoice-preview', [Staff\AccountingController::class, 'invoicePreview']);
                Route::post('invoices/from-dispatch/{dispatch}', [Staff\AccountingController::class, 'invoiceFromDispatch']);
                Route::post('tax-settings', [Staff\AccountingController::class, 'saveTaxSetting']);
                Route::post('labour-rates', [Staff\AccountingController::class, 'saveLabourRate']);
                Route::delete('labour-rates/{labourRate}', [Staff\AccountingController::class, 'deleteLabourRate']);
                Route::post('financial-years/{financialYear}/lock', [Staff\AccountingController::class, 'lock']);
            });

            // Reports & analytics (spec §12) and the audit trail (spec §14).
            Route::middleware('permission:'.P::REPORTS_VIEW)->group(function () {
                Route::get('reports', [Staff\ReportController::class, 'index']);
                Route::get('reports/{name}', [Staff\ReportController::class, 'show']);
            });
            Route::get('audit', [Staff\AuditController::class, 'index'])->middleware('permission:'.P::AUDIT_VIEW);

            Route::middleware('permission:'.P::USERS_MANAGE)->group(function () {
                Route::apiResource('users', Staff\UserController::class)->only(['index', 'store', 'update']);
                Route::apiResource('roles', Staff\RoleController::class)->only(['index', 'store', 'update']);
            });
        });
    });

    // ── Retailer portal ──────────────────────────────────────────────────────
    Route::prefix('portal/v1')->group(function () {
        Route::post('register', [PortalAuth::class, 'register'])->middleware('throttle:signup');

        Route::middleware(['auth:retailer', 'tenant.member:retailer', 'workspace.writable', 'idempotent'])->group(function () {
            Route::get('me', [PortalAuth::class, 'me']);
            Route::get('catalogue/filters', [Portal\CatalogueController::class, 'filters']);
            Route::get('designs', [Portal\CatalogueController::class, 'index']);
            Route::get('designs/{code}', [Portal\CatalogueController::class, 'show']);
            Route::get('gold-rate', [Portal\CatalogueController::class, 'goldRate']);
            Route::get('cart', [Portal\CartController::class, 'index']);
            Route::post('cart', [Portal\CartController::class, 'store']);
            Route::patch('cart/{item}', [Portal\CartController::class, 'update']);
            Route::delete('cart/{item}', [Portal\CartController::class, 'destroy']);
            Route::get('orders', [Portal\OrderController::class, 'index']);
            Route::post('orders', [Portal\OrderController::class, 'store']);
            Route::get('orders/{order}', [Portal\OrderController::class, 'show']);
            Route::post('orders/{order}/reorder', [Portal\OrderController::class, 'reorder']);
            Route::post('orders/{order}/lines/{line}', [Portal\OrderController::class, 'updateLine']);   // multipart (reference photo)
            Route::get('dispatches/{dispatch}/documents/{attachment}', [Portal\OrderController::class, 'document']);
            Route::post('wishlist/{design}', [Portal\CatalogueController::class, 'wishlist']);
            Route::delete('wishlist/{design}', [Portal\CatalogueController::class, 'unwishlist']);
            Route::get('statement.pdf', [Portal\AccountController::class, 'statement']);
            Route::get('queries', [Portal\QueryController::class, 'index']);
            Route::post('queries', [Portal\QueryController::class, 'store']);
            Route::get('queries/{query}', [Portal\QueryController::class, 'show']);
            Route::post('queries/{query}/reply', [Portal\QueryController::class, 'reply']);
            Route::get('notifications', [NotificationController::class, 'index'])->defaults('guard', 'retailer');
            Route::post('notifications/read', [NotificationController::class, 'read'])->defaults('guard', 'retailer');
            Route::get('account', [Portal\AccountController::class, 'show']);
            Route::get('invoices/{voucher}/pdf', [Portal\AccountController::class, 'invoicePdf']);
            Route::get('challans/{dispatch}/pdf', [Portal\AccountController::class, 'challanPdf']);
            Route::get('team', [Portal\AccountController::class, 'team']);
            Route::post('team', [Portal\AccountController::class, 'addUser']);
            Route::patch('team/{user}', [Portal\AccountController::class, 'updateUser']);
        });
    });

    // ── Kaarigar app ─────────────────────────────────────────────────────────
    Route::prefix('kaarigar/v1')->group(function () {
        Route::middleware(['auth:kaarigar', 'tenant.member:kaarigar', 'workspace.writable', 'idempotent'])->group(function () {
            Route::get('me', [KaarigarAuth::class, 'me']);
            Route::get('home', [KaarigarApp::class, 'home']);
            Route::get('issues', [KaarigarApp::class, 'issues']);
            Route::post('issues/{transaction}/accept', [KaarigarApp::class, 'accept']);
            Route::post('issues/{transaction}/dispute', [KaarigarApp::class, 'dispute']);
            Route::get('jobs', [KaarigarApp::class, 'jobs']);
            Route::post('jobs/{jobCard}/status', [KaarigarApp::class, 'setJobStatus']);
            Route::post('jobs/{jobCard}/return', [KaarigarApp::class, 'requestReturn']);
            Route::post('jobs/{jobCard}/handoff', [KaarigarApp::class, 'handoff']);
            Route::post('jobs/{jobCard}/hand-back', [KaarigarApp::class, 'handBack']);
            Route::get('team', [KaarigarApp::class, 'team']);
            Route::get('requests', [KaarigarApp::class, 'requests']);
            Route::get('khata', [KaarigarApp::class, 'khata']);
            Route::get('earnings', [KaarigarApp::class, 'earnings']);
            Route::get('notifications', [NotificationController::class, 'index'])->defaults('guard', 'kaarigar');
            Route::post('notifications/read', [NotificationController::class, 'read'])->defaults('guard', 'kaarigar');
        });
    });
});
