# Jewellery Manufacturing SaaS

Multi-tenant SaaS for gold jewellery manufacturers: gold ledger and kaarigar tracking, orders and work orders, a B2B retailer portal, accounting and hallmarking. The full spec is in `Jewellery-SaaS-V1-Spec.pdf`.

**Status: Phases 1–4 done** (foundation, gold ledger + kaarigar app, production + hallmarking + dispatch, accounting with GST). Next: the spec's second gate (books match the pilot's CA), then Phase 5, the B2B retailer portal.

One app and one login for everyone. After signing in, each person lands on their own area:

| Who | Signs in with | Lands on |
|---|---|---|
| Staff (owner, metal manager, …) | email + password | `/app/staff` (manufacturer dashboard) |
| Retailer | email + password | `/app/portal` |
| Kaarigar | mobile number + OTP | `/app/kaarigar` |

```
backend/    Laravel 12 REST API (PHP 8.3 on cPanel); also serves the built app
frontend/   npm workspaces
  apps/web       the app: login + staff/, kaarigar/, portal/ areas (each loaded on demand)
  packages/ui    shared components, API client, tenant branding, PWA bits
```

## Run locally

```bash
# API
cd backend
composer install
php artisan migrate:fresh --seed     # demo workspace "acme"
php artisan serve                    # http://127.0.0.1:8000

# Apps (separate terminal)
cd frontend
cp .env.example .env                 # VITE_TENANT=acme
npm install
npm run dev                          # http://localhost:5173/app/
```

Demo logins (seeded), all on the same sign-in page:

| Who | Login |
|---|---|
| Staff | `owner@acme.test` / `password` (all permissions), `metal@acme.test` / `password` (metal manager) |
| Super-admin (you) | `/app/admin`: `admin@platform.test` / `password` |
| Retailer portal | `shop@sharma.test` / `password` |
| Kaarigar | 9876500001 (Ramesh), 9876500002, 9876500003. The OTP is shown on screen in local dev and written to `storage/logs/laravel.log` |

Locally there are no subdomains: the Vite proxy sends `X-Tenant: acme` to the API. Use the Vite URLs in dev. `php artisan serve` mis-routes deep links under `/app/…`, which Apache doesn't.

Tests: `cd backend && php artisan test`. Type checks: `cd frontend && npm run typecheck`.

## How it fits together

- **Tenancy:** one database, with a `tenant_id` on every tenant-owned row (`BelongsToTenant` trait plus a global scope). The tenant comes from the subdomain (`acme.yourapp.in`), or from the `X-Tenant` header on non-tenant hosts. If no tenant is resolved, queries return no rows and writes throw, so a mistake can't leak data. Validation must use `TenantRules::exists()/unique()`, never the plain `exists:`/`unique:` rules.
- **Auth:** one sign-in (`/api/auth/login`, `/api/auth/otp`, `/api/auth/otp/verify`, `/api/auth/me`, `/api/auth/logout`). The server checks staff first, then retailers, and starts the matching Sanctum session guard: `staff`, `retailer` or `kaarigar`. Signing in as one type signs out the others on that device. Underneath, each API group still only accepts its own guard, so a retailer can never reach `/api/v1/*`.
- **Roles:** spatie/laravel-permission with teams on `tenant_id`. Each tenant gets owner, metal_manager, production_manager, sales and accounts roles, and can add custom ones. Permission names are in `app/Support/Permissions.php`.
- **Weights:** stored as `DECIMAL(12,3)` grams and `DECIMAL(6,3)` purity, and calculated with bcmath in `App\Support\Metal\Weight`, never floats. The API returns them as strings, e.g. `"91.600"`.
- **Idempotency:** every authenticated write accepts an `Idempotency-Key` header, and the shared client sends one on every write. A repeated key replays the stored response instead of posting twice.
- **Gold ledger:** `App\Services\Metal\Ledger` is the only writer. Every line moves fine gold from one location to another (vault, kaarigar, scrap, finished, loss, outside…), so all balances sum to zero. Entries are append-only (fix with a reversal). Stock can't go negative; a closed day is locked. `MetalOperations` holds the business rules (issue, receive, close job card with allowed wastage, melt, refine, deposit). `php artisan metal:rebuild-balances` runs nightly and checks every ledger sums to zero.
- **Production:** `App\Services\Production\Production` turns order lines into work orders with a route; gold goes Vault → kaarigar (first stage) → WO → kaarigar (next stage) → … → WO → finished pieces (one `finished_items` row each) → AHC (`Hallmarking`) → retailer (`Dispatching`, challan). Gold left in a completed WO goes to scrap.
- **Push:** set `WEBPUSH_PUBLIC_KEY` / `WEBPUSH_PRIVATE_KEY` (generate with `npx web-push generate-vapid-keys`). New issues notify the kaarigar, over-limit wastage notifies owners, dispatch notifies the retailer.
- **Accounting:** `App\Services\Accounting\Books` is the only writer of the money books; every voucher balances in ₹ and in fine grams. Invoices come from dispatches (`Invoicing`), labour bills from closed job cards (`Labour`), metal receipts from retailer deposits. Reports: trial balance, P&L, balance sheet, party ledgers, outstanding/ageing, cash/bank book, GSTR-1/3B, stock valuation, Tally XML. E-invoice JSON is generated; plug a GSP into `GspClient` for IRNs.
- **Numbering:** `NumberSeries::next('ORD')` gives `ORD/26-27/0001`, numbered per tenant and per Indian financial year.
- **Audit:** spatie activitylog records every change to the master models, stamped with the tenant and IP. The log is never pruned.
- **PWA:** one installable app (scope `/app/`). Laravel serves its manifest per tenant at `/manifest/app.webmanifest`, so each manufacturer's name and colour appear on the installed icon. Laravel also serves deep links with the tenant branding injected into the page.

## Deploying to cPanel

1. Point the domain and `*.yourapp.in` at `backend/public`. This needs wildcard DNS and wildcard SSL.
2. PHP 8.3 with the extensions bcmath, ctype, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, tokenizer, xml, zip and gmp.
3. Set up `.env` on the server: MySQL, `APP_ENV=production`, `TENANCY_CENTRAL_DOMAIN=yourapp.in`, `SANCTUM_STATEFUL_DOMAINS=yourapp.in,*.yourapp.in`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `KAARIGAR_EXPOSE_OTP=false`. Then run `php artisan storage:link`.
4. Add a cron job that runs every minute: `cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`. The scheduler also works through the job queue each minute.
5. Push to `main`. `.github/workflows/deploy.yml` runs the tests, builds the app, uploads everything over SSH, then migrates and caches.

## Still open before production

- **SMS provider for kaarigar OTP:** needs a DLT-registered sender ID and template. Only the `log` driver exists so far.
- **Wildcard SSL:** check whether your host supports it. If not, use path-based tenants, which the `X-Tenant` header resolution already prepares for.
- **Super-admin panel and subscription billing:** the data model is in place (plans, subscriptions, tenant status), but there are no screens yet.
- **Password reset** for staff and retailers.
