<?php

return [
    // The signing secret shown on Hamsukhan → Integrations for this connector.
    'secret' => env('HAMSUKHAN_WEBHOOK_SECRET', ''),

    // Class implementing Hamsukhan\Connector\Contracts\EventHandler that saves the booking/order.
    'handler' => env('HAMSUKHAN_HANDLER'),

    'route' => [
        'enabled' => true,
        'path' => 'hamsukhan/webhook',
        // No "web" middleware on purpose: the request is signed, so CSRF doesn't apply.
        'middleware' => ['api'],
    ],
];
