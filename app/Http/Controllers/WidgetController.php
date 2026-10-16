<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Services\VapiService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]));
    }

    public function start(Request $request, string $publicKey, WalletService $wallet, VapiService $vapi): JsonResponse
    {
        $bot = Bot::with('user')->where('public_key', $publicKey)->firstOrFail();

        if (! $bot->is_active) {
            return $this->cors(response()->json(['ok' => false, 'reason' => 'inactive'], 409));
        }

        $maxSeconds = $wallet->maxAffordableSeconds($bot->user, $bot->max_call_minutes);
        if ($maxSeconds < 30) {
            return $this->cors(response()->json(['ok' => false, 'reason' => 'no_balance'], 402));
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
            'Access-Control-Allow-Headers' => 'Content-Type',
        ]);
    }
}
