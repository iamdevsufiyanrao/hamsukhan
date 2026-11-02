<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Services\BotProvisioner;
use App\Services\VapiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Stores: each store is its own bot (prompt, voice, widget, calls, bookings) inside one account.
 * The plan decides how many (Basic 1, Business 5). Everyone in the account can switch; only the owner adds or removes.
 */
class StoreController extends Controller
{
    public function store(Request $request, BotProvisioner $provisioner): RedirectResponse
    {
        $owner = $request->user();
        $name = $request->validate(['name' => ['required', 'string', 'max:80']])['name'];
        $limit = $owner->planLimits()['stores'];
        if ($owner->bots()->count() >= $limit) {
            return back()->withErrors(['name' => $limit === 1
                ? 'Your plan includes 1 store. Upgrade to Business for up to 5 stores.'
                : "Your plan includes {$limit} stores."]);
        }

        $bot = $provisioner->provision($owner, $name);
        $owner->forceFill(['current_bot_id' => $bot->id])->save();

        return redirect()->route('bot-studio')->with('success', "Store \"{$name}\" added. Set up its assistant here.");
    }

    public function switch(Request $request, Bot $bot): RedirectResponse
    {
        $user = $request->user();
        abort_unless($bot->user_id === $user->account()->id, 404);
        $user->forceFill(['current_bot_id' => $bot->id])->save();

        return back()->with('success', 'Switched to '.($bot->business_name ?: 'store #'.$bot->id));
    }

    public function destroy(Request $request, Bot $bot, VapiService $vapi): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($bot->user_id === $owner->id, 404);
        $request->validate(['confirm' => ['required', 'in:DELETE']], ['confirm.in' => 'Type DELETE to remove this store.']);
        if ($owner->bots()->count() <= 1) {
            return back()->with('error', 'Your account needs at least one store.');
        }

        $vapi->deleteAssistant($bot);
        Storage::deleteDirectory("knowledge/{$bot->id}");
        $bot->delete(); // calls, bookings, connectors and campaigns go with it (cascade)
        $owner->members()->where('current_bot_id', $bot->id)->update(['current_bot_id' => null]);
        if ($owner->current_bot_id === $bot->id) {
            $owner->forceFill(['current_bot_id' => null])->save();
        }

        return redirect()->route('settings')->with('success', 'Store removed');
    }
}
