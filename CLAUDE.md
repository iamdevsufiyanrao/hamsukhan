# Hamsukhan — project guide

AI receptionist SaaS: a business gets a voice bot that answers website calls in the owner's **cloned voice**,
follows their prompt, records every call (transcript + cost), captures bookings/orders, and bills a prepaid wallet.

Business rules live in [docs/BUSINESS_LOGIC.md](docs/BUSINESS_LOGIC.md) — read it before changing billing, calls, bookings, connectors or admin.
Planned next: [docs/LIVE_CALL_ACTIONS.md](docs/LIVE_CALL_ACTIONS.md) (needs Vapi keys).

## Stack
- Laravel 13 + Inertia.js 3 + React 19 + TypeScript (no Tailwind — design tokens in `resources/css/app.css`)
- SQLite locally, MySQL on the host (migrations are portable)
- Providers: **Vapi** (call engine), **ElevenLabs** (voice cloning), **OpenAI** (LLM inside Vapi)
- No real phone numbers — calls come only from the website widget (`public/widget.js`)
- Payments: **manual top-ups** (JazzCash / Easypaisa / bank → admin approves). No Stripe (company not registered).

## Run it (Windows, this machine)
```bat
serve.bat                      :: http://127.0.0.1:8123  (runs php -S from public\ with OPcache)
npm run build                  :: after any change in resources/js or resources/css
php artisan migrate:fresh --seed   :: reset demo data
php artisan test               :: 65 feature tests, must stay green
php artisan schedule:work      :: runs connector retries every minute (cron schedule:run on the host)
npx tsc --noEmit -p .          :: type-check the frontend
```
- Composer is not on PATH: `php %USERPROFILE%\.composer-bin\composer.phar ...`
- F: is a slow HDD: the first cold request can take 30s+. Always start the server from `public\` (serve.bat does).
- `F:\Backend\ownclone\Myclony\` is the user's original design-images folder (the product was renamed MyClony → Hamsukhan on 2026-10-16) — leave it alone.
- `F:\Backend\Sifututor` is another project of the user's with its own PHP servers on port 8000 — never stop those processes.

## Demo logins (seeded, password `password`)
- `a@smartgeeks.io` — Smart Geeks repair shop, ~60 days of calls
- `sana@bloomsalon.pk` — Bloom Salon, new account
- `ali@karachigrill.pk` — Karachi Grill restaurant: orders with items/address, 2 connectors
- `admin@hamsukhan.test` — super admin at `/admin`: accounts, login-as, suspend, balance, payments

## Where things are
| Concern | Code |
|---|---|
| Money (only place balance changes) | `app/Services/WalletService.php` |
| Vapi assistant sync | `app/Services/VapiService.php` |
| Voice cloning | `app/Services/ElevenLabsService.php` |
| Call report → call + transcript + booking + charge | `app/Services/CallIngestService.php` |
| Vapi webhook | `POST /api/webhooks/vapi` → `VapiWebhookController` |
| Website widget API | `GET /api/widget/{key}/config`, `POST /api/widget/{key}/start` → `WidgetController` |
| Sample conversations (seeder + simulated test calls) | `app/Support/CallScenarios.php` |
| What the bot collects (custom fields) | `app/Support/CaptureFields.php` |
| Connectors: payload, SSRF guard, delivery + retries | `app/Services/Integrations/*`, command `hamsukhan:deliver-integrations` |
| Customer-side package (Laravel + plain PHP) | `packages/hamsukhan-connector` (autoloaded here for tests) |
| Super admin | `app/Http/Controllers/Admin/*`, audit log `AdminAction::record()` |
| Settings & prices | Defaults in `config/hamsukhan.php` + `.env`; the super admin's values (Settings page, `settings` table, secrets encrypted) override them at boot via `PlatformSettings::apply()` |
| Modules / roles overview (for people) | `docs/MODULES_AND_ROLES.md` |
| Pages | `resources/js/pages/*` (one per route), layout `resources/js/layouts/AppLayout.tsx` |
| Shared page props (user, wallet, badges, flash) | `app/Http/Middleware/HandleInertiaRequests.php` |

## Conventions
- Controllers stay thin; anything touching money or provider APIs goes through a Service.
- Every customer query is scoped through `$request->user()->bot` — never look up calls/bookings/voices by id alone.
- Pages get data as Inertia props shaped in the controller (camelCase keys). No mock data in the frontend.
- Saves redirect back with `->with('success', '...')`; the layout turns flash into a toast.
- Provider keys empty ⇒ **simulated mode** (local `sim_*` ids, nothing sent). Keep that path working: tests and the demo rely on it.
- Add a feature test for every business rule you add or change.
- Anything a super admin does to another account must call `AdminAction::record()`.
- Never change the signature format in `packages/hamsukhan-connector/src/Signature.php` without versioning it (`v2=`) — customers depend on it.
- Edit PHP files with the Edit tool, not shell `node -e`/`sed` one-liners: shell quoting has silently eaten namespace backslashes here twice.

## Workflow
1. Read `docs/BUSINESS_LOGIC.md` and the relevant service.
2. Change backend → add or adjust a test → `php artisan test`.
3. Change frontend → `npx tsc --noEmit -p .` → `npm run build`.
4. If the schema changed: new migration (never edit old ones once deployed) + update the seeder.
5. Check the page in the browser (serve.bat) before calling it done.

## Going live (checklist)
- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`, MySQL `DB_*`, real `MAIL_*`
- Set `VAPI_PRIVATE_KEY`, `VAPI_PUBLIC_KEY`, `VAPI_WEBHOOK_SECRET` (required in production), `ELEVENLABS_API_KEY`
- Add the ElevenLabs key inside the Vapi dashboard too (provider credentials), and restrict the Vapi public key to your domains
- Set real `HAMSUKHAN_JAZZCASH_*`, `HAMSUKHAN_EASYPAISA_*`, `HAMSUKHAN_BANK_*` payment accounts and `HAMSUKHAN_PKR_PER_USD`
- `php artisan migrate --force`, `php artisan storage:link`, `npm run build`, then `php artisan optimize`
- Verify Vapi API field names in `VapiService` / `CallIngestService` against the current Vapi docs on first real call
