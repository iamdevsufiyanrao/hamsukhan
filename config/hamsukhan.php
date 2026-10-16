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

    'languages' => [
        'English' => 'en',
        'Urdu' => 'ur',
        'Arabic' => 'ar',
        'Hindi' => 'hi',
        'Spanish' => 'es',
    ],

    'preset_voices' => [
        ['id' => 'preset_john', 'name' => 'John · male (en-UK)'],
        ['id' => 'preset_sara', 'name' => 'Sara · female (en-US)'],
        ['id' => 'preset_ahmed', 'name' => 'Ahmed · male (ur-PK)'],
    ],
];
