<?php

namespace App\Http\Controllers;

use App\Services\CallIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Receives Vapi server messages. Only "end-of-call-report" changes data. */
class VapiWebhookController extends Controller
{
    public function __invoke(Request $request, CallIngestService $ingest): JsonResponse
    {
        // Production must have a secret; locally an empty secret is allowed for testing.
        $secret = config('hamsukhan.vapi.webhook_secret');
        abort_if(! $secret && app()->isProduction(), 401);
        if ($secret && ! hash_equals($secret, (string) $request->header('x-vapi-secret'))) {
            abort(401);
        }

        $message = (array) $request->input('message', []);

        if (($message['type'] ?? null) === 'end-of-call-report') {
            $call = $ingest->ingestEndOfCallReport($message);

            return response()->json(['ok' => true, 'callId' => $call?->id]);
        }

        return response()->json(['ok' => true]);
    }
}
