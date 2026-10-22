<?php

return [

    /*
    | Billing. The wallet is kept in USD because Vapi bills in USD.
    | Manual top-ups are paid in PKR and converted with `pkr_per_usd`.
    */
    'rate_per_minute' => (float) env('HAMSUKHAN_RATE_PER_MINUTE', 0.12),
    'min_topup_usd' => (float) env('HAMSUKHAN_MIN_TOPUP_USD', 5),
    'pkr_per_usd' => (float) env('HAMSUKHAN_PKR_PER_USD', 280),
    'low_balance_usd' => (float) env('HAMSUKHAN_LOW_BALANCE_USD', 5),
    'max_cloned_voices' => 5,
    'min_clone_seconds' => 30,

    /*
    | Where customers send money for manual top-ups (shown on the Billing page).
    */
    'payment_accounts' => [
        'jazzcash' => ['label' => 'JazzCash', 'title' => env('HAMSUKHAN_JAZZCASH_TITLE', 'Hamsukhan'), 'number' => env('HAMSUKHAN_JAZZCASH_NUMBER', '0300 0000000')],
        'easypaisa' => ['label' => 'Easypaisa', 'title' => env('HAMSUKHAN_EASYPAISA_TITLE', 'Hamsukhan'), 'number' => env('HAMSUKHAN_EASYPAISA_NUMBER', '0345 0000000')],
        'bank' => ['label' => 'Bank transfer', 'title' => env('HAMSUKHAN_BANK_TITLE', 'Hamsukhan'), 'number' => env('HAMSUKHAN_BANK_IBAN', 'PK00 XXXX 0000 0000 0000 0000')],
    ],

    /*
    | Providers. Leave keys empty to run in "simulated" mode: nothing is sent to
    | Vapi/ElevenLabs and local placeholder IDs are used instead.
    */
    'vapi' => [
        'private_key' => env('VAPI_PRIVATE_KEY'),
        'public_key' => env('VAPI_PUBLIC_KEY'),
        'webhook_secret' => env('VAPI_WEBHOOK_SECRET'),
        'base_url' => env('VAPI_BASE_URL', 'https://api.vapi.ai'),
        'model' => env('VAPI_MODEL', 'gpt-4o'),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),
        'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
    ],

    /*
    | WhatsApp Cloud API (developers.facebook.com → WhatsApp). Without a token, WhatsApp alerts are skipped.
    | Business-initiated messages need an approved template; `template` is its name, with one body
    | variable {{1}} that receives the alert text. Without a template, plain text is sent (only delivered
    | if the owner messaged the business number in the last 24 hours).
    */
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'template' => env('WHATSAPP_TEMPLATE'),
        'template_lang' => env('WHATSAPP_TEMPLATE_LANG', 'en'),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com/v21.0'),
    ],

    /*
    | Google Calendar (console.cloud.google.com → APIs & Services → Credentials → OAuth client ID, type
    | "Web application"). Authorised redirect URI: https://YOUR-DOMAIN/integrations/google/callback.
    | Enable the "Google Calendar API" for the project.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    /*
    | Owner alerts. Low-balance alerts fire once each time the balance drops below each threshold (USD).
    */
    'notifications' => [
        'low_balance_thresholds' => [5, 1],
        'daily_summary_at' => '21:00', // Asia/Karachi
    ],

    'openai' => [
        // Optional: your own OpenAI key for the LLM inside Vapi (otherwise Vapi's built-in key is used).
        'api_key' => env('OPENAI_API_KEY'),
    ],

    /*
    | Site & SEO, editable by the super admin in Settings (DB values override these defaults).
    */
    'site' => [
        'name' => env('APP_NAME', 'Hamsukhan'),
        'support_email' => env('HAMSUKHAN_SUPPORT_EMAIL'),
        'support_phone' => env('HAMSUKHAN_SUPPORT_PHONE'),
        'whatsapp' => env('HAMSUKHAN_WHATSAPP'),
        // Block the dashboard until the email is verified (turn on once outgoing mail works).
        'require_email_verification' => (bool) env('HAMSUKHAN_REQUIRE_EMAIL_VERIFICATION', false),
        // Where error alerts go (falls back to support_email).
        'alert_email' => env('HAMSUKHAN_ALERT_EMAIL'),
        'allow_signups' => true,
        'announcement' => null,
        'terms_url' => null,
        'privacy_url' => null,
    ],

    'seo' => [
        'title' => 'Hamsukhan · AI receptionist in your own voice',
        'description' => 'An AI receptionist that answers your website calls in your cloned voice, takes bookings and orders, and sends them to your software.',
        'keywords' => 'AI receptionist, voice bot, voice cloning, restaurant orders, appointment booking, website voice assistant',
        'og_image' => null,
        'index' => true,
        'ga_id' => null,
    ],

    'integrations' => [
        'enabled' => true,
        'form_enabled' => true,
        // Allow webhook URLs on localhost / private networks (for testing a receiver on your own machine). Never in production.
        'allow_private_urls' => (bool) env('HAMSUKHAN_ALLOW_PRIVATE_URLS', env('APP_ENV') === 'local'),
        'max_per_bot' => 5,
    ],

    'outcome_tags' => ['Booking', 'Order', 'Enquiry', 'Pricing', 'Support', 'No outcome'],

    /*
    | Languages the bot can be set to. Only languages with their own QA are listed (Pakistan-first).
    | `code` goes to the transcriber; "Urdu + English" transcribes multilingual so callers can code-switch.
    */
    'languages' => [
        'English' => 'en',
        'Urdu' => 'ur',
        'Urdu + English' => 'multi',
    ],

    /*
    | Speech-to-text inside Vapi per language code. Deepgram has no Urdu model, so Urdu and mixed calls use
    | ElevenLabs Scribe (which handles Urdu/English code-switching). Overridable per code via config.
    */
    'transcribers' => [
        'en' => ['provider' => 'deepgram', 'model' => 'nova-2', 'language' => 'en'],
        'ur' => ['provider' => '11labs', 'model' => 'scribe_v1', 'language' => 'ur'],
        'multi' => ['provider' => '11labs', 'model' => 'scribe_v1'],
    ],

    /*
    | Stock voices offered before an owner clones their own. `voice_id` is a real ElevenLabs premade voice
    | (works through Vapi without cloning). Change them with HAMSUKHAN_VOICE_* in .env.
    */
    'preset_voices' => [
        ['id' => 'preset_sara', 'name' => 'Sarah · female, professional (default)', 'voice_id' => env('HAMSUKHAN_VOICE_SARA', 'EXAVITQu4vr4xnSDxMaL')],
        ['id' => 'preset_john', 'name' => 'George · male, warm', 'voice_id' => env('HAMSUKHAN_VOICE_JOHN', 'JBFqnCBsd6RMkjVDRZzb')],
        ['id' => 'preset_ahmed', 'name' => 'Brian · male, calm', 'voice_id' => env('HAMSUKHAN_VOICE_AHMED', 'nPczCjzI2devNBz1zQrb')],
    ],

    /*
    | Public demo on the landing page: never billed to any wallet, capped and rate limited.
    */
    'demo' => [
        'bot_key' => env('HAMSUKHAN_DEMO_BOT_KEY'),
        'max_seconds' => 90,
        'per_ip_per_hour' => 5,
    ],
];
