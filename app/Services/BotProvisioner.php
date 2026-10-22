<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\User;

/** Creates the default bot every new account starts with. */
class BotProvisioner
{
    public function __construct(private VapiService $vapi) {}

    public function provision(User $user): Bot
    {
        $bot = $user->bot()->create([
            'greeting' => 'Hi, thanks for calling! How can I help you today?',
            'end_message' => 'Thanks for calling, have a great day!',
            'language' => 'English',
            'prompt' => "You are a friendly receptionist for [Business name].\n\n- Greet callers and ask how you can help.\n- For appointments, collect name, phone number and preferred time.\n- If you don't know something, say a team member will call back.\n- Keep answers short and natural.",
            'preset_voice' => config('hamsukhan.preset_voices.0.id'),
        ]);

        // Never let a provider problem block signup: the bot is saved either way and the error shows in Bot Studio.
        $this->vapi->trySync($bot);

        return $bot;
    }
}
