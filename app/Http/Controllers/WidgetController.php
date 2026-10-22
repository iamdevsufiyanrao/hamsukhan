<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Services\Integrations\IntegrationDispatcher;
use App\Services\VapiService;
use App\Services\WalletService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Public endpoints used by widget.js on customers' websites (and by "Test your bot" in the dashboard).
 * No secrets are ever returned, only the Vapi PUBLIC key and the assistant id.
 */
class WidgetController extends Controller
{
    public function config(Request $request, string $publicKey): JsonResponse
    {
        $bot = Bot::where('public_key', $publicKey)->firstOrFail();
        $this->markInstalled($request, $bot);

        return $this->cors(response()->json([
            'active' => $bot->is_active,
            'widget' => $bot->widget,
            'business' => $bot->business_name,
            'language' => $bot->language,
            'privacyUrl' => config('hamsukhan.site.privacy_url') ?: route('privacy'),
        ]));
    }

    public function start(Request $request, string $publicKey, WalletService $wallet, VapiService $vapi): JsonResponse
    {
        $bot = Bot::with('user')->where('public_key', $publicKey)->firstOrFail();

        if (! $bot->is_active || $bot->user->isSuspended()) {
            return $this->cors(response()->json(['ok' => false, 'reason' => 'inactive', 'message' => 'This assistant is switched off.'], 409));
        }

        $maxSeconds = $wallet->maxAffordableSeconds($bot->user, $bot->max_call_minutes);
        if ($maxSeconds < 30) {
            return $this->cors(response()->json(['ok' => false, 'reason' => 'no_balance', 'message' => 'Not enough balance for a call.'], 402));
        }

        if ($error = $this->ensureLive($bot, $vapi)) {
            return $this->cors(response()->json(['ok' => false, 'reason' => 'not_synced', 'message' => $error], 503));
        }

        return $this->cors(response()->json([
            'ok' => true,
            'simulated' => ! $vapi->enabled(),
            'vapiPublicKey' => $vapi->publicKey(),
            'assistantId' => $bot->vapi_assistant_id,
            'overrides' => [
                'maxDurationSeconds' => $maxSeconds,
                'metadata' => ['botId' => $bot->id, 'isTest' => $request->boolean('test')],
            ],
        ]));
    }

    /** "Request a callback" from the widget: the human fallback when a visitor can't or won't talk to the AI. */
    public function callback(Request $request, string $publicKey, IntegrationDispatcher $integrations): JsonResponse
    {
        $bot = Bot::where('public_key', $publicKey)->firstOrFail();
        // Validated by hand so the error answer carries CORS headers (the widget runs on other domains).
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:300'],
            'preferredTime' => ['nullable', 'string', 'max:60'],
            'consent' => ['accepted'],
        ]);
        if ($validator->fails()) {
            return $this->cors(response()->json(['ok' => false, 'message' => $validator->errors()->first()], 422));
        }
        $data = $validator->validated();
        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            return $this->cors(response()->json(['ok' => false, 'message' => 'Please enter a valid phone number, e.g. 0300 1234567.'], 422));
        }

        $booking = $bot->bookings()->create([
            'type' => 'callback',
            'source' => 'widget',
            'customer_name' => $data['name'],
            'phone' => $phone,
            'request' => ($data['message'] ?? null) ?: 'Asked for a callback from the website',
            'preferred_time' => $data['preferredTime'] ?? null,
        ]);
        rescue(fn () => $integrations->dispatchForBooking($booking->setRelation('bot', $bot)));

        return $this->cors(response()->json(['ok' => true]));
    }

    /**
     * Live demo on the landing page: talks to the bot the super admin picked as the demo bot.
     * Never billed to any wallet, capped at hamsukhan.demo.max_seconds and rate limited per IP.
     */
    public function demo(Request $request, VapiService $vapi): JsonResponse
    {
        $bot = ($key = config('hamsukhan.demo.bot_key')) ? Bot::where('public_key', $key)->first() : null;
        if (! $bot || ! $vapi->enabled()) {
            return response()->json(['ok' => false, 'reason' => 'unavailable', 'message' => 'The live demo is not switched on yet. Request a callback instead.'], 503);
        }

        $limiterKey = 'demo:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('hamsukhan.demo.per_ip_per_hour'))) {
            return response()->json(['ok' => false, 'reason' => 'rate_limited', 'message' => 'You have tried the demo a few times already. Please try again in an hour.'], 429);
        }
        if ($error = $this->ensureLive($bot, $vapi)) {
            return response()->json(['ok' => false, 'reason' => 'not_synced', 'message' => 'The demo is having trouble right now. Please try again later.'], 503);
        }
        RateLimiter::hit($limiterKey, 3600);

        // The visitor's language pick only changes the opening line; the bot follows whatever language they speak.
        $greeting = [
            'urdu' => 'Assalam o Alaikum! Main Hamsukhan ki AI receptionist hoon. Aap booking, timings ya charges ke baare mein pooch sakte hain.',
            'english' => 'Hi! I am the Hamsukhan AI receptionist. Ask me about a booking, our timings or prices.',
            'mixed' => 'Hello, Assalam o Alaikum! Main AI receptionist hoon, aap Urdu ya English mein baat kar sakte hain. How can I help?',
        ][$request->input('language')] ?? null;

        return response()->json([
            'ok' => true,
            'vapiPublicKey' => $vapi->publicKey(),
            'assistantId' => $bot->vapi_assistant_id,
            'overrides' => array_filter([
                'maxDurationSeconds' => (int) config('hamsukhan.demo.max_seconds'),
                'firstMessage' => $greeting,
                'metadata' => ['botId' => $bot->id, 'isDemo' => true],
            ]),
        ]);
    }

    /** A bot whose last sync failed (or never reached Vapi) gets one more try before the call. Returns the error, or null. */
    private function ensureLive(Bot $bot, VapiService $vapi): ?string
    {
        if (! $vapi->enabled() || $vapi->isLive($bot)) {
            return null;
        }

        return $vapi->trySync($bot) ? 'This assistant is not ready yet, the owner needs to fix its setup in Bot Studio.' : null;
    }

    private function markInstalled(Request $request, Bot $bot): void
    {
        $origin = parse_url((string) $request->headers->get('origin'), PHP_URL_HOST);
        if ($origin && $origin !== parse_url(config('app.url'), PHP_URL_HOST) && ! in_array($origin, ['127.0.0.1', 'localhost'], true) && ! $bot->installed_at) {
            $bot->forceFill(['installed_at' => now(), 'website' => $bot->website ?? $origin])->save();
        }
    }

    private function cors(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept',
        ]);
    }
}
