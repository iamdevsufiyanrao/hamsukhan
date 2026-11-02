<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\ShopifyShop;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connects a Shopify store to the open Hamsukhan store (bot) through the Hamsukhan Shopify app.
 * Started from Integrations (connect) or from Shopify's admin (install = the app URL); both end in callback.
 */
class ShopifyController extends Controller
{
    private const SESSION = 'shopify_oauth';

    public function connect(Request $request, ShopifyService $shopify): Response
    {
        abort_unless($shopify->enabled(), 409, 'The Shopify app is not switched on for the platform yet.');
        $shop = ShopifyService::normaliseShop($request->validate(['shop' => ['required', 'string', 'max:120']])['shop']);
        if (! $shop) {
            return back()->withErrors(['shop' => 'Enter your Shopify store address, e.g. mystore.myshopify.com']);
        }

        return Inertia::location($this->startOAuth($request, $shopify, $shop, $request->user()->bot));
    }

    /** Shopify's "App URL": the merchant clicked install/open in Shopify admin. */
    public function install(Request $request, ShopifyService $shopify): RedirectResponse
    {
        abort_unless($shopify->enabled(), 404);
        $shop = (string) $request->query('shop');
        abort_unless(ShopifyService::isValidShop($shop) && $shopify->verifyHmac($request->query()), 401, 'Invalid Shopify request.');

        $user = $request->user();
        if (! $user) {
            return redirect()->guest(route('login'))->with('status', 'Log in to Hamsukhan to connect your Shopify store.');
        }
        if (! $user->bot || $user->is_admin || ! in_array($user->team_role, [null, 'admin'], true)) {
            return redirect()->route('dashboard')->with('error', 'Only the account owner or an admin can connect a Shopify store.');
        }

        return redirect()->away($this->startOAuth($request, $shopify, $shop, $user->bot));
    }

    public function callback(Request $request, ShopifyService $shopify): RedirectResponse
    {
        abort_unless($shopify->enabled(), 404);
        $pending = $request->session()->pull(self::SESSION);
        $shop = (string) $request->query('shop');
        if (! $pending || ! hash_equals($pending['state'], (string) $request->query('state')) || $pending['shop'] !== $shop) {
            return redirect()->route('integrations')->with('error', 'The Shopify connection expired. Please press Connect again.');
        }
        if (! $shopify->verifyHmac($request->query()) || ! $request->query('code')) {
            return redirect()->route('integrations')->with('error', 'Shopify did not confirm the connection. Please try again.');
        }
        $bot = Bot::whereKey($pending['bot'])->where('user_id', $request->user()->account()->id)->first();
        abort_unless($bot, 404);

        $existing = ShopifyShop::with('bot')->where('shop', $shop)->first();
        if ($existing && $existing->bot?->user_id !== $bot->user_id) {
            return redirect()->route('integrations')->with('error', "{$shop} is already connected to another Hamsukhan account.");
        }

        try {
            $token = $shopify->exchangeCode($shop, (string) $request->query('code'));
            $record = $existing ?? new ShopifyShop(['shop' => $shop]);
            $record->fill(['bot_id' => $bot->id, 'access_token' => $token['access_token'], 'scopes' => $token['scope'], 'installed_at' => now(), 'uninstalled_at' => null])->save();
            $record->setRelation('bot', $bot);
            $shopify->installWidget($record);
            rescue(fn () => $shopify->registerUninstallWebhook($record));
        } catch (RuntimeException $e) {
            return redirect()->route('integrations')->with('error', 'Shopify: '.$e->getMessage());
        }

        return redirect()->route('integrations')->with('success', "{$shop} is connected. The call button now shows on your Shopify store.");
    }

    public function destroy(Request $request, ShopifyShop $shop, ShopifyService $shopify): RedirectResponse
    {
        abort_unless($shop->bot_id === $request->user()->bot->id, 404);
        if (! $shop->uninstalled_at) {
            rescue(fn () => $shopify->removeWidget($shop->setRelation('bot', $request->user()->bot)), report: false);
        }
        $shop->delete();

        return back()->with('success', "{$shop->shop} disconnected, the call button is removed from it.");
    }

    /** app/uninstalled + the mandatory privacy webhooks. We keep no Shopify customer data, so those only need a 200. */
    public function webhook(Request $request, ShopifyService $shopify): JsonResponse
    {
        abort_unless($shopify->enabled(), 404);
        if (! $shopify->verifyWebhook($request->getContent(), $request->header('X-Shopify-Hmac-Sha256'))) {
            return response()->json(['ok' => false], 401);
        }
        $shop = (string) ($request->header('X-Shopify-Shop-Domain') ?: $request->input('shop_domain') ?: $request->input('myshopify_domain'));

        match ((string) $request->header('X-Shopify-Topic')) {
            'app/uninstalled' => ShopifyShop::where('shop', $shop)->update(['uninstalled_at' => now(), 'script_tag_id' => null]),
            'shop/redact' => ShopifyShop::where('shop', $shop)->delete(),
            default => null, // customers/data_request, customers/redact
        };

        return response()->json(['ok' => true]);
    }

    private function startOAuth(Request $request, ShopifyService $shopify, string $shop, Bot $bot): string
    {
        $state = Str::random(40);
        $request->session()->put(self::SESSION, ['state' => $state, 'shop' => $shop, 'bot' => $bot->id]);

        return $shopify->authorizeUrl($shop, $state);
    }
}
