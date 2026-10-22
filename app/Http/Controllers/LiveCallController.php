<?php

namespace App\Http\Controllers;

use App\Models\LiveCall;
use App\Services\VapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Calls in progress: list (polled by the dashboard), listen link, make the bot say something, end the call. */
class LiveCallController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bot = $request->user()->bot;
        // A call we never got the "ended" message for is dropped after 2 hours.
        $bot->liveCalls()->where('started_at', '<', now()->subHours(2))->delete();
        $canListen = $request->user()->team_role !== 'viewer';

        return response()->json([
            'calls' => $bot->liveCalls()->latest('started_at')->get()->map(fn (LiveCall $c) => [
                'id' => $c->id,
                'direction' => $c->direction,
                'customer' => $c->customer_number,
                'startedAt' => $c->started_at?->toIso8601String(),
                'listenUrl' => $canListen ? $c->listen_url : null,
                'canControl' => $canListen && (bool) $c->control_url,
            ]),
            'audio' => ['sampleRate' => (int) config('hamsukhan.vapi.listen_sample_rate'), 'channels' => (int) config('hamsukhan.vapi.listen_channels')],
        ]);
    }

    public function say(Request $request, LiveCall $liveCall, VapiService $vapi): JsonResponse
    {
        $this->authorizeCall($request, $liveCall);
        $data = $request->validate(['text' => ['required', 'string', 'max:300']]);

        return $this->send($vapi, $liveCall, ['type' => 'say', 'content' => $data['text'], 'endCallAfterSpoken' => false]);
    }

    /** Whisper an instruction to the bot (the caller does not hear it). */
    public function instruct(Request $request, LiveCall $liveCall, VapiService $vapi): JsonResponse
    {
        $this->authorizeCall($request, $liveCall);
        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);

        return $this->send($vapi, $liveCall, ['type' => 'add-message', 'message' => ['role' => 'system', 'content' => 'Instruction from the business owner (do not read it out): '.$data['text']], 'triggerResponseEnabled' => true]);
    }

    public function end(Request $request, LiveCall $liveCall, VapiService $vapi): JsonResponse
    {
        $this->authorizeCall($request, $liveCall);

        return $this->send($vapi, $liveCall, ['type' => 'end-call']);
    }

    private function send(VapiService $vapi, LiveCall $call, array $message): JsonResponse
    {
        if (! $call->control_url) {
            return response()->json(['message' => 'This call cannot be controlled.'], 409);
        }
        try {
            $vapi->control($call->control_url, $message);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true]);
    }

    private function authorizeCall(Request $request, LiveCall $call): void
    {
        abort_unless($call->bot_id === $request->user()->bot->id, 404);
        abort_if($request->user()->team_role === 'viewer', 403, 'Viewers cannot control calls.');
    }
}
