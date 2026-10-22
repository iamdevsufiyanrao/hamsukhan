<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Browser errors from the dashboard (ErrorBoundary / window.onerror) land in the server log with the user and page. */
class ClientErrorController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'stack' => ['nullable', 'string', 'max:3000'],
            'url' => ['nullable', 'string', 'max:300'],
        ]);
        Log::warning('[client] '.$data['message'], ['url' => $data['url'] ?? null, 'user' => $request->user()?->id, 'agent' => mb_substr((string) $request->userAgent(), 0, 200), 'stack' => $data['stack'] ?? null]);

        return response()->json(['ok' => true]);
    }
}
