<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_is_super_admin_only(): void
    {
        $this->actingAs($this->customer())->get('/admin/settings')->assertForbidden();
        foreach (['site', 'seo', 'billing', 'providers', 'plugins', 'system'] as $tab) {
            $this->actingAs($this->admin())->get("/admin/settings?tab={$tab}")->assertOk();
        }
        $this->actingAs($this->customer())->patch('/admin/settings/billing', ['rate_per_minute' => 0.01])->assertForbidden();
    }

    public function test_billing_settings_override_config_and_change_real_charges(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch('/admin/settings/billing', ['rate_per_minute' => 0.20, 'pkr_per_usd' => 300])->assertSessionHas('success');

        $this->assertEquals(0.20, config('hamsukhan.rate_per_minute'));
        // A fresh boot (next request) picks it up from the DB, not from .env.
        Cache::forget('hamsukhan:settings');
        config(['hamsukhan.rate_per_minute' => 0.12]);
        app(\App\Services\PlatformSettings::class)->apply();
        $this->assertEquals(0.20, config('hamsukhan.rate_per_minute'));

        $this->assertEquals(0.2, app(\App\Services\WalletService::class)->costFor(60));
        $this->assertTrue(AdminAction::where('action', 'update_settings')->exists());
    }

    public function test_api_keys_are_encrypted_masked_and_kept_when_left_blank(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch('/admin/settings/providers', ['vapi_private_key' => 'sk_live_supersecret_9F2A', 'vapi_public_key' => 'pk_123']);

        $raw = Setting::where('key', 'vapi_private_key')->value('value');
        $this->assertStringNotContainsString('supersecret', $raw);
        $this->assertSame('sk_live_supersecret_9F2A', config('hamsukhan.vapi.private_key'));

        // The page never contains the key itself, only a hint.
        $page = $this->actingAs($admin)->get('/admin/settings?tab=providers')->assertOk()->getContent();
        $this->assertStringNotContainsString('supersecret', $page);
        $this->assertStringContainsString('9F2A', $page);

        // Saving again with the field blank keeps the old key.
        $this->actingAs($admin)->patch('/admin/settings/providers', ['vapi_private_key' => '', 'vapi_public_key' => 'pk_456']);
        $this->assertSame('sk_live_supersecret_9F2A', config('hamsukhan.vapi.private_key'));
        $this->assertSame('pk_456', config('hamsukhan.vapi.public_key'));

        // Audit log records which keys changed, never the values.
        $this->assertStringNotContainsString('supersecret', AdminAction::where('action', 'update_settings')->get()->toJson());

        $this->actingAs($admin)->delete('/admin/settings/secret/vapi_private_key');
        $this->assertNull(Setting::where('key', 'vapi_private_key')->first());
    }

    public function test_keys_from_settings_switch_services_out_of_simulated_mode(): void
    {
        $this->assertFalse(app(\App\Services\VapiService::class)->enabled());
        $this->actingAs($this->admin())->patch('/admin/settings/providers', ['vapi_private_key' => 'sk_x', 'elevenlabs_api_key' => 'el_x']);
        $this->assertTrue(app(\App\Services\VapiService::class)->enabled());
        $this->assertTrue(app(\App\Services\ElevenLabsService::class)->enabled());
    }

    public function test_test_connection_reports_provider_answer(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings/test/vapi')->assertSessionHas('error'); // no key yet

        $this->actingAs($admin)->patch('/admin/settings/providers', ['vapi_private_key' => 'bad', 'elevenlabs_api_key' => 'good']);
        Http::fake([
            'api.vapi.ai/*' => Http::response(['message' => 'Unauthorized'], 401),
            'api.elevenlabs.io/*' => Http::response(['subscription' => ['tier' => 'creator', 'can_use_instant_voice_cloning' => true]]),
        ]);
        $this->actingAs($admin)->post('/admin/settings/test/vapi')->assertSessionHas('error', fn ($m) => str_contains($m, '401'));
        $this->actingAs($admin)->post('/admin/settings/test/elevenlabs')->assertSessionHas('success', fn ($m) => str_contains($m, 'creator'));
    }

    public function test_closing_signups_blocks_registration(): void
    {
        $this->actingAs($this->admin())->patch('/admin/settings/site', ['allow_signups' => false, 'site_name' => 'Hamsukhan']);
        auth()->logout();

        $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'terms' => true])->assertForbidden();
        $this->assertSame(0, \App\Models\User::where('email', 'x@example.com')->count());
    }

    public function test_seo_settings_render_in_page_head_and_ga_id_is_validated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch('/admin/settings/seo', ['seo_ga_id' => '"><script>alert(1)</script>'])->assertSessionHasErrors('seo_ga_id');
        $this->actingAs($admin)->patch('/admin/settings/seo', ['seo_title' => 'Hamsukhan · AI receptionist', 'seo_description' => 'Answers calls in your voice', 'seo_index' => true, 'seo_ga_id' => 'G-ABC1234567'])->assertSessionHasNoErrors();
        auth()->logout();

        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="description" content="Answers calls in your voice">', $html);
        $this->assertStringContainsString('index,follow', $html);
        $this->assertStringContainsString('G-ABC1234567', $html);
    }

    public function test_announcement_is_shared_with_customers(): void
    {
        $this->actingAs($this->admin())->patch('/admin/settings/site', ['announcement' => 'Maintenance on Sunday', 'allow_signups' => true]);
        $this->actingAs($this->customer())->get('/dashboard')->assertOk()->assertSee('Maintenance on Sunday');
    }

    public function test_connectors_switch_and_form_rule(): void
    {
        config(['hamsukhan.integrations.allow_private_urls' => true]);
        $admin = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin)->patch('/admin/settings/plugins', ['connectors_enabled' => true, 'form_connectors_enabled' => false, 'connectors_max_per_bot' => 5]);
        $this->actingAs($customer)->post('/integrations', ['name' => 'F', 'kind' => 'form', 'url' => 'https://pos.test/x', 'events' => ['order.created'], 'fieldMap' => [['target' => 'a', 'source' => 'phone']]])
            ->assertSessionHasErrors('kind');

        $this->actingAs($admin)->patch('/admin/settings/plugins', ['connectors_enabled' => false, 'form_connectors_enabled' => true, 'connectors_max_per_bot' => 5]);
        $this->actingAs($customer)->post('/integrations', ['name' => 'W', 'kind' => 'webhook', 'url' => 'https://crm.test/x', 'events' => ['order.created']])
            ->assertSessionHasErrors('url');
    }

    public function test_admin_can_pause_any_connector_and_run_cron(): void
    {
        $admin = $this->admin();
        $integration = $this->customer()->bot->integrations()->create(['name' => 'CRM', 'kind' => 'webhook', 'url' => 'https://crm.test/hook', 'events' => ['booking.created']]);

        $this->actingAs($admin)->post("/admin/settings/connectors/{$integration->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($integration->fresh()->is_active);
        $this->actingAs($admin)->post('/admin/settings/cron/run')->assertSessionHas('success');
        $this->assertTrue(AdminAction::where('action', 'connector_off')->exists());
    }

    public function test_cron_heartbeat_is_reported(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/settings?tab=system')->assertOk()->assertSee('"healthy":false', false);

        $this->artisan('schedule:run')->assertSuccessful();
        // Must be a plain int: real cache stores can't unserialize objects (cache.serializable_classes is off).
        $this->assertIsInt(Cache::get('hamsukhan:cron_last_run'));
        $this->actingAs($admin)->get('/admin/settings?tab=system')->assertSee('"healthy":true', false);
    }
}
