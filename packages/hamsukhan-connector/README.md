# Hamsukhan Connector

Receive the bookings and orders your Hamsukhan AI receptionist takes on calls — straight into your own PHP or Laravel software.

When the bot finishes a call that produced a booking/order, Hamsukhan sends a signed `POST` to your URL.
This package checks the signature, gives you a tidy `Event` object, and answers Hamsukhan with your record id.
If your server is down or returns 5xx, Hamsukhan retries after 1 min, 5 min, 30 min and 2 hours.

## Laravel

1. Copy this folder into your project, e.g. `packages/hamsukhan-connector`, and add it to `composer.json`:

   ```json
   "repositories": [{ "type": "path", "url": "packages/hamsukhan-connector" }]
   ```

   then run `composer require hamsukhan/connector:@dev`. The service provider registers itself.

2. `.env`

   ```
   HAMSUKHAN_WEBHOOK_SECRET=whsec_...        # from Hamsukhan → Integrations → your connector
   HAMSUKHAN_HANDLER=App\Hamsukhan\SaveOrder
   ```

3. Write the handler:

   ```php
   namespace App\Hamsukhan;

   use Hamsukhan\Connector\Contracts\EventHandler;
   use Hamsukhan\Connector\Event;

   class SaveOrder implements EventHandler
   {
       public function handle(Event $event): ?string
       {
           if ($event->isTest()) {
               return 'TEST-OK';
           }

           if ($event->isOrder()) {
               $order = \App\Models\Order::create([
                   'customer' => $event->customerName(),
                   'phone'    => $event->phone(),
                   'items'    => $event->field('items'),
                   'address'  => $event->field('address'),
                   'source'   => 'hamsukhan',
               ]);

               return 'ORD-'.$order->id;
           }

           $appointment = \App\Models\Appointment::create([
               'name'  => $event->customerName(),
               'phone' => $event->phone(),
               'when'  => $event->preferredTime(),
               'notes' => $event->request(),
           ]);

           return 'APT-'.$appointment->id;
       }
   }
   ```

4. In Hamsukhan → Integrations → Add connector → **Webhook (JSON)**, URL `https://your-app.com/hamsukhan/webhook`. Click **Send test**.

Prefer events? Listen to `Hamsukhan\Connector\Laravel\EventReceived` instead of (or as well as) setting a handler.
Publish the config with `php artisan vendor:publish --tag=hamsukhan-connector` to change the route path or middleware.

## Plain PHP (no framework)

```php
<?php // hamsukhan-webhook.php
require __DIR__.'/hamsukhan-connector/src/Signature.php';
require __DIR__.'/hamsukhan-connector/src/InvalidSignature.php';
require __DIR__.'/hamsukhan-connector/src/Event.php';
require __DIR__.'/hamsukhan-connector/src/Contracts/EventHandler.php';
require __DIR__.'/hamsukhan-connector/src/Receiver.php';

$pdo = new PDO('mysql:host=localhost;dbname=shop', 'user', 'pass');

Hamsukhan\Connector\Receiver::run('whsec_...', function (Hamsukhan\Connector\Event $e) use ($pdo) {
    $pdo->prepare('INSERT INTO orders (customer, phone, items, address) VALUES (?, ?, ?, ?)')
        ->execute([$e->customerName(), $e->phone(), $e->field('items'), $e->field('address')]);

    return 'ORD-'.$pdo->lastInsertId();
});
```

## What arrives

Headers: `Hamsukhan-Signature: t=<unix>,v1=<hmac>`, `Hamsukhan-Event`, `Hamsukhan-Delivery` (unique id — retries reuse it).

```json
{
  "event": "order.created",
  "id": "4a7c…",
  "test": false,
  "created_at": "2026-10-16T15:04:11+00:00",
  "bot": { "id": 3, "business": "Karachi Grill" },
  "booking": {
    "id": 118, "type": "order", "status": "new",
    "customer_name": "Bilal Ahmed", "phone": "0301 5551234",
    "request": "Delivery: Mixed BBQ platter, 1.5L Coke", "preferred_time": "ASAP",
    "details": { "order_type": "delivery", "items": "Mixed BBQ platter, 1.5L Coke", "address": "Flat 7B, Clifton Block 5" }
  },
  "call": { "id": 512, "started_at": "…", "duration_seconds": 96, "summary": "Delivery order placed…" }
}
```

`details` holds the custom fields you set in Hamsukhan → Bot Studio → "What should the bot collect?".

Answer with any 2xx and JSON `{"ok": true, "reference": "ORD-1042"}` — the reference appears next to the booking in Hamsukhan.
4xx (except 408/429) is treated as permanent and not retried.

## Verifying the signature yourself

`v1` = `HMAC-SHA256(secret, "<t>.<raw request body>")` as hex. Reject if `t` is more than 5 minutes old.
`Hamsukhan\Connector\Signature::verify($body, $header, $secret)` does exactly this.
