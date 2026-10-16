<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Integration;
use App\Models\IntegrationDelivery;
use App\Models\Setting;
use App\Services\Integrations\IntegrationDispatcher;
use App\Services\PlatformSettings;
use App\Services\ProviderCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Super admin: site, SEO, billing, provider API keys, plugins (connectors) and cron/system health. */
class SettingsController extends Controller
{
    public const TABS = ['site', 'seo', 'billing', 'providers', 'plugins', 'system'];

    public function __construct(private PlatformSettings $settings) {}

    public function show(Request $request): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'site';

        return Inertia::render('Admin/Settings', [
            'tab' => $tab,
            'values' => $this->settings->forForm(),
            'plugins' => [
                'connectors' => Integration::with('bot.user:id,name,email')->latest()->limit(100)->get()->map(fn (Integration $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'kind' => $i->kind,
                    'url' => $i->url,
                    'isActive' => $i->is_active,
                    'lastStatus' => $i->last_status,
                    'lastDeliveryAt' => $i->last_delivery_at?->toIso8601String(),
                    'account' => $i->bot?->user ? ['id' => $i->bot->user->id, 'name' => $i->bot->user->name, 'business' => $i->bot->business_name] : null,
                ]),
                'stats' => [
                    'total' => Integration::count(),
                    'active' => Integration::where('is_active', true)->count(),
                    'delivered24h' => IntegrationDelivery::where('status', 'delivered')->where('updated_at', '>=', now()->subDay())->count(),
                    'failed24h' => IntegrationDelivery::where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
                    'pending' => IntegrationDelivery::where('status', 'pending')->count(),
                ],
                'packageVersion' => '1.0.0',
            ],
            'system' => $this->system(),
        ]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        abort_unless(in_array($group, ['site', 'seo', 'billing', 'providers', 'plugins'], true), 404);
        $keys = array_keys(array_filter(PlatformSettings::SCHEMA, fn ($s) => $s[3] === $group));

        $rules = [];
        foreach ($keys as $key) {
            $type = PlatformSettings::SCHEMA[$key][1];
            $rules[$key] = match ($type) {
                'bool' => ['boolean'],
                'number' => ['nullable', 'numeric', 'min:0', 'max:100000'],
                'email' => ['nullable', 'email', 'max:150'],
                'url' => ['nullable', 'url', 'max:300'],
                'text' => ['nullable', 'string', 'max:500'],
                default => ['nullable', 'string', 'max:300'],
            };
        }
        $rules['seo_ga_id'] = ['nullable', 'regex:/^(G|UA|GT)-[A-Z0-9-]{4,20}$/i'];
        $rules['rate_per_minute'] = ['nullable', 'numeric', 'min:0.01', 'max:5'];
        $rules['connectors_max_per_bot'] = ['nullable', 'integer', 'min:1', 'max:50'];

        $data = $request->validate(array_intersect_key($rules, array_flip($keys)));
        $changed = $this->settings->save($data, $request->user());

        AdminAction::record($request->user(), null, 'update_settings', [
            'group' => $group,
            // Never log secret values, only which keys changed.
            'keys' => $changed,
        ]);

        return back()->with('success', 'Settings saved');
    }

    public function clearSecret(Request $request, string $key): RedirectResponse
    {
        abort_unless((PlatformSettings::SCHEMA[$key][2] ?? false) === true, 404);
        $this->settings->clearSecret($key, $request->user());
        AdminAction::record($request->user(), null, 'update_settings', ['group' => 'providers', 'keys' => [$key], 'cleared' => true]);

        return back()->with('success', 'Key removed, falls back to .env (or simulated mode)');
    }

    public function test(string $provider, ProviderCheck $check): RedirectResponse
    {
        $result = match ($provider) {
            'vapi' => $check->vapi(),
            'elevenlabs' => $check->elevenLabs(),
            default => abort(404),
        };

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function toggleConnector(Request $request, Integration $integration): RedirectResponse
    {
        $integration->update(['is_active' => ! $integration->is_active]);
        AdminAction::record($request->user(), $integration->bot?->user, $integration->is_active ? 'connector_on' : 'connector_off', ['connector' => $integration->name]);

        return back()->with('success', $integration->is_active ? 'Connector turned on' : 'Connector paused');
    }

    public function runCron(Request $request, IntegrationDispatcher $dispatcher): RedirectResponse
    {
        $n = $dispatcher->deliverDue();
        AdminAction::record($request->user(), null, 'run_cron', ['attempted' => $n]);

        return back()->with('success', "Ran due connector deliveries now, {$n} attempted");
    }

    private function system(): array
    {
        $lastRun = Cache::get('hamsukhan:cron_last_run');
        // realpath works for symlinks and Windows junctions (is_dir/is_link don't see junctions).
        $linked = realpath(public_path('storage')) !== false && realpath(public_path('storage')) === realpath(storage_path('app/public'));

        return [
            'cron' => [
                'lastRun' => $lastRun,
                'healthy' => $lastRun && now()->diffInMinutes($lastRun, true) <= 3,
                'line' => '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
                'jobs' => [
                    ['name' => 'Connector retries', 'command' => 'hamsukhan:deliver-integrations', 'every' => 'Every minute'],
                    ['name' => 'Cron heartbeat', 'command' => 'heartbeat', 'every' => 'Every minute'],
                ],
            ],
            'checks' => [
                ['label' => 'Environment', 'value' => app()->environment(), 'ok' => true],
                ['label' => 'Debug mode', 'value' => config('app.debug') ? 'ON' : 'off', 'ok' => ! (config('app.debug') && app()->isProduction())],
                ['label' => 'App URL', 'value' => config('app.url'), 'ok' => ! app()->isProduction() || str_starts_with(config('app.url'), 'https://')],
                ['label' => 'Database', 'value' => DB::connection()->getDriverName(), 'ok' => true],
                ['label' => 'Storage link', 'value' => $linked ? 'linked' : 'missing, run php artisan storage:link', 'ok' => $linked],
                ['label' => 'Vapi', 'value' => config('hamsukhan.vapi.private_key') ? 'connected' : 'simulated mode', 'ok' => (bool) config('hamsukhan.vapi.private_key') || ! app()->isProduction()],
                ['label' => 'Vapi webhook secret', 'value' => config('hamsukhan.vapi.webhook_secret') ? 'set' : 'not set', 'ok' => (bool) config('hamsukhan.vapi.webhook_secret') || ! app()->isProduction()],
                ['label' => 'ElevenLabs', 'value' => config('hamsukhan.elevenlabs.api_key') ? 'connected' : 'simulated mode', 'ok' => (bool) config('hamsukhan.elevenlabs.api_key') || ! app()->isProduction()],
                ['label' => 'Mail', 'value' => config('mail.default'), 'ok' => config('mail.default') !== 'log' || ! app()->isProduction()],
                ['label' => 'PHP · Laravel', 'value' => PHP_VERSION.' · '.app()->version(), 'ok' => true],
            ],
            'webhookUrl' => route('webhooks.vapi'),
            'settingsUpdatedAt' => Setting::max('updated_at'),
        ];
    }
}
