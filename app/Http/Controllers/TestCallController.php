<?php

namespace App\Http\Controllers;

use App\Services\CallIngestService;
use App\Services\VapiService;
use App\Support\CallScenarios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finishes a test call from the dashboard while Vapi isn't connected yet:
 * it feeds a simulated end-of-call-report through the same ingest + billing path a real call uses.
 */
class TestCallController extends Controller
{
    public function __invoke(Request $request, CallIngestService $ingest, VapiService $vapi): JsonResponse
    {
        abort_if($vapi->enabled(), 409, 'Vapi is connected, real calls are recorded by the webhook.');
        $data = $request->validate(['seconds' => ['required', 'integer', 'min:1', 'max:900']]);

        $bot = $request->user()->bot;
        $call = $ingest->ingestEndOfCallReport(CallScenarios::endOfCallReport($bot, max(20, $data['seconds']), true, random_int(0, 999)));

        return response()->json(['ok' => true, 'callId' => $call?->id, 'cost' => (float) $call?->cost]);
    }
}
