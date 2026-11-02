<?php

namespace Tests\Feature;

use App\Models\ShopifyShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Shopify app: OAuth connect, widget as a ScriptTag, disconnect and webhooks. */
class ShopifyTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'bloom.myshopify.com';

    private function enable(): void
    {
        config(['hamsukhan.shopify.enabled' => true, 'hamsukhan.shopify.client_id' => 'cid', 'hamsukhan.shopify.client_secret' => 'csecret']);
    }

    private function signed(array $query): array
    {
        ksort($query);
        $msg = collect($query)->map(fn ($v, $k) => "{$k}={$v}")->implode('&');

        return $query + ['hmac' => hash_hmac('sha256', $msg, 'csecret')];
    }

    /** Starts the OAuth flow and returns the state Hamsukhan put in the authorize URL. */
    private function connect($user, string $shop = 'bloom'): string
    {
        $res = $this->actingAs($user)->post('/integrations/shopify/connect', ['shop' => $shop], ['X-Inertia' => 'true']);
        $res->assertStatus(409);
        $location = $res->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://'.self::SHOP.'/admin/oauth/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame(['cid', 'write_script_tags', route('shopify.callback')], [$q['client_id'], $q['scope'], $q['redirect_uri']]);

        return $q['state'];
    }

    private function fakeShopify(): void
    {
        Http::fake([
            self::SHOP.'/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_secret', 'scope' => 'write_script_tags']),
            self::SHOP.'/admin/api/*/script_tags.json' => Http::response(['script_tag' => ['id' => 777]], 201),
            self::SHOP.'/admin/api/*/script_tags/*' => Http::response([], 200),
            self::SHOP.'/admin/api/*/webhooks.json' => Http::response(['webhook' => ['id' => 1]], 201),
        ]);
    }

    public function test_everything_is_off_until_the_admin_configures_it(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->post('/integrations/shopify/connect', ['shop' => 'bloom'])->assertStatus(409);
        $this->get('/shopify/install?shop='.self::SHOP)->assertNotFound();
        $this->postJson('/api/shopify/webhooks', [])->assertNotFound();
        $this->actingAs($user)->get('/integrations')->assertInertia(fn ($p) => $p->where('shopify.enabled', false));

        // Toggle on but no secret: still off.
        config(['hamsukhan.shopify.enabled' => true, 'hamsukhan.shopify.client_id' => 'cid']);
        $this->actingAs($user)->get('/integrations')->assertInertia(fn ($p) => $p->where('shopify.enabled', false));
    }

    public function test_connect_validates_the_shop_and_redirects_to_shopify(): void
    {
        $this->enable();
        $user = $this->customer();
        $this->actingAs($user)->post('/integrations/shopify/connect', ['shop' => 'not a shop!'])->assertSessionHasErrors('shop');
        $this->actingAs($user)->post('/integrations/shopify/connect', ['shop' => 'evil.example.com'])->assertSessionHasErrors('shop');
        $this->assertNotEmpty($this->connect($user, 'https://Bloom.myshopify.com/admin'));
    }

    public function test_callback_with_bad_state_or_hmac_is_refused(): void
    {
        $this->enable();
        $this->fakeShopify();
        $user = $this->customer();

        $state = $this->connect($user);
        $this->actingAs($user)->get('/integrations/shopify/callback?'.http_build_query($this->signed(['shop' => self::SHOP, 'code' => 'c', 'state' => 'wrong'])))
            ->assertRedirect('/integrations')->assertSessionHas('error');

        $state = $this->connect($user);
        $this->actingAs($user)->get('/integrations/shopify/callback?'.http_build_query(['shop' => self::SHOP, 'code' => 'c', 'state' => $state, 'hmac' => 'forged']))
            ->assertRedirect('/integrations')->assertSessionHas('error');

        $this->assertSame(0, ShopifyShop::count());
        Http::assertNothingSent();
    }

    public function test_good_callback_connects_the_shop_and_adds_the_widget(): void
    {
        $this->enable();
        $this->fakeShopify();
        $user = $this->customer();
        $user->bot->update(['allowed_domains' => ['bloomsalon.pk']]);

        $state = $this->connect($user);
        $this->actingAs($user)->get('/integrations/shopify/callback?'.http_build_query($this->signed(['shop' => self::SHOP, 'code' => 'c0de', 'state' => $state, 'timestamp' => '1700000000'])))
            ->assertRedirect('/integrations')->assertSessionHas('success');

        $shop = ShopifyShop::firstOrFail();
        $this->assertSame([$user->bot->id, '777'], [$shop->bot_id, $shop->script_tag_id]);
        $this->assertSame('shpat_secret', $shop->access_token);
        $this->assertNotSame('shpat_secret', DB::table('shopify_shops')->value('access_token')); // encrypted at rest
        $this->assertSame(['bloomsalon.pk', self::SHOP], $user->bot->fresh()->allowed_domains);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/script_tags.json') && $r->hasHeader('X-Shopify-Access-Token', 'shpat_secret')
            && $r['script_tag']['src'] === url('/widget.js').'?bot='.$user->bot->public_key);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/webhooks.json') && $r['webhook']['topic'] === 'app/uninstalled' && $r['webhook']['address'] === route('shopify.webhook'));

        $this->actingAs($user)->get('/integrations')->assertInertia(fn ($p) => $p->where('shopify.enabled', true)->where('shopify.shops.0.shop', self::SHOP)->where('shopify.shops.0.widgetOn', true));
    }

    public function test_an_empty_allow_list_stays_empty(): void
    {
        $this->enable();
        $this->fakeShopify();
        $user = $this->customer();
        $state = $this->connect($user);
        $this->actingAs($user)->get('/integrations/shopify/callback?'.http_build_query($this->signed(['shop' => self::SHOP, 'code' => 'c', 'state' => $state])));

        $this->assertEmpty($user->bot->fresh()->allowed_domains);
    }

    public function test_a_shop_connected_to_another_account_is_refused(): void
    {
        $this->enable();
        $this->fakeShopify();
        $other = $this->customer();
        ShopifyShop::create(['bot_id' => $other->bot->id, 'shop' => self::SHOP, 'access_token' => 'x', 'installed_at' => now()]);

        $user = $this->customer();
        $state = $this->connect($user);
        $this->actingAs($user)->get('/integrations/shopify/callback?'.http_build_query($this->signed(['shop' => self::SHOP, 'code' => 'c', 'state' => $state])))
            ->assertSessionHas('error');
        $this->assertSame($other->bot->id, ShopifyShop::firstOrFail()->bot_id);
    }

    public function test_disconnect_removes_the_widget_and_the_row_only_for_its_owner(): void
    {
        $this->enable();
        $this->fakeShopify();
        $user = $this->customer();
        $shop = ShopifyShop::create(['bot_id' => $user->bot->id, 'shop' => self::SHOP, 'access_token' => 'tok', 'script_tag_id' => '777', 'installed_at' => now()]);

        $this->actingAs($this->customer())->delete("/integrations/shopify/{$shop->id}")->assertNotFound();
        $this->actingAs($user)->delete("/integrations/shopify/{$shop->id}")->assertSessionHas('success');
        $this->assertSame(0, ShopifyShop::count());
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/script_tags/777.json'));
    }

    public function test_viewers_cannot_connect(): void
    {
        $this->enable();
        $owner = $this->customer('business');
        $viewer = new \App\Models\User(['name' => 'V', 'email' => 'v@example.com', 'password' => 'secret-pass']);
        $viewer->forceFill(['owner_id' => $owner->id, 'team_role' => 'viewer'])->save();

        $this->actingAs($viewer)->post('/integrations/shopify/connect', ['shop' => 'bloom'])->assertSessionHas('error');
    }

    public function test_install_link_from_shopify_needs_hmac_and_a_login(): void
    {
        $this->enable();
        $this->get('/shopify/install?shop='.self::SHOP.'&hmac=bad')->assertStatus(401);

        $query = http_build_query($this->signed(['shop' => self::SHOP, 'timestamp' => '1700000000']));
        $this->get('/shopify/install?'.$query)->assertRedirect('/login');

        $res = $this->actingAs($this->customer())->get('/shopify/install?'.$query);
        $this->assertStringStartsWith('https://'.self::SHOP.'/admin/oauth/authorize?', $res->headers->get('Location'));
    }

    public function test_webhooks_need_a_valid_hmac_and_handle_uninstall_and_redact(): void
    {
        $this->enable();
        $user = $this->customer();
        ShopifyShop::create(['bot_id' => $user->bot->id, 'shop' => self::SHOP, 'access_token' => 'tok', 'script_tag_id' => '777', 'installed_at' => now()]);
        $send = function (string $topic, ?string $hmac = null) {
            $body = json_encode(['shop_domain' => self::SHOP]);
            $hmac ??= base64_encode(hash_hmac('sha256', $body, 'csecret', true));

            return $this->call('POST', '/api/shopify/webhooks', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac, 'HTTP_X_SHOPIFY_TOPIC' => $topic, 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::SHOP], $body);
        };

        $send('app/uninstalled', 'forged')->assertStatus(401);
        $this->assertNull(ShopifyShop::firstOrFail()->uninstalled_at);

        $send('app/uninstalled')->assertOk();
        $shop = ShopifyShop::firstOrFail();
        $this->assertNotNull($shop->uninstalled_at);
        $this->assertNull($shop->script_tag_id);

        $send('customers/data_request')->assertOk();
        $send('shop/redact')->assertOk();
        $this->assertSame(0, ShopifyShop::count());
    }
}
