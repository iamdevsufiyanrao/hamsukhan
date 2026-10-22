<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Bot;
use App\Models\User;
use App\Services\VapiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Super admin: phone numbers in the Vapi account (bought in Vapi or imported from Twilio / Telnyx / SIP)
 * and which customer's bot answers each one. A bot's number is also used for its outbound calls.
 */
class PhoneNumberController extends Controller
{
    public function index(VapiService $vapi): Response
    {
        $error = null;
        $numbers = [];
        try {
            $numbers = $vapi->phoneNumbers();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        $bots = Bot::with('user:id,name,email')->whereIn('vapi_phone_number_id', array_column($numbers, 'id'))->get()->keyBy('vapi_phone_number_id');

        return Inertia::render('Admin/PhoneNumbers', [
            'enabled' => $vapi->enabled(),
            'error' => $error,
            'defaultOutboundId' => config('hamsukhan.vapi.phone_number_id'),
            'numbers' => collect($numbers)->map(fn ($n) => $n + [
                'account' => ($b = $bots[$n['id']] ?? null) ? ['id' => $b->user->id, 'name' => $b->user->name, 'email' => $b->user->email] : null,
            ]),
            'accounts' => User::where('is_admin', false)->whereHas('bot')->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'label' => "{$u->name} · {$u->email}"]),
        ]);
    }

    public function assign(Request $request, VapiService $vapi): RedirectResponse
    {
        $data = $request->validate([
            'phoneNumberId' => ['required', 'string', 'max:64'],
            'userId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_admin', false)],
        ]);

        try {
            $number = collect($vapi->phoneNumbers())->firstWhere('id', $data['phoneNumberId']);
            if (! $number) {
                return back()->with('error', 'That number is not in the Vapi account.');
            }
            $bot = $data['userId'] ? User::findOrFail($data['userId'])->bot : null;
            if ($bot && ! $vapi->isLive($bot)) {
                return back()->with('error', 'That bot is not live on Vapi yet (see its Bot Studio). Fix it first, then assign the number.');
            }
            $vapi->assignPhoneNumber($number['id'], $bot);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Bot::where('vapi_phone_number_id', $number['id'])->update(['vapi_phone_number_id' => null, 'phone_number' => null]);
        if ($bot) {
            $bot->update(['vapi_phone_number_id' => $number['id'], 'phone_number' => $number['number']]);
        }
        AdminAction::record($request->user(), $bot?->user, 'assign_phone_number', ['number' => $number['number'], 'phone_number_id' => $number['id']]);

        return back()->with('success', $bot ? "{$number['number']} now rings {$bot->user->name}'s assistant" : "{$number['number']} is not assigned any more");
    }
}
