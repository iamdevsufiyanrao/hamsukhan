# Hamsukhan — Modules, Roles & How the Plugin Works

**Hamsukhan** (Urdu/Farsi: *a companion you can talk to*) is an AI receptionist for businesses.
It answers calls from the business's website **in the owner's own cloned voice**, talks to the caller,
takes bookings and orders, records every call, and sends those bookings/orders into the business's own software.

Status as of 16 Oct 2026: everything below is built and tested (65 automated tests plus browser end-to-end runs).
Voice calls run in **simulated mode** until real Vapi/ElevenLabs keys are entered. The super admin can now
enter those keys under Settings → API keys.

---

## 1. Who uses it (roles)

| Role | Who they are | How they get in |
|---|---|---|
| **Website visitor (caller)** | A customer of the business who clicks the call button on the business's website | No login. Uses the website widget |
| **Business owner (customer)** | Restaurant, clinic, shop, salon… that buys Hamsukhan | Signs up at `/register`, logs in at `/login` |
| **Business's developer** | Whoever maintains the business's own software (POS, CRM, booking system) | Installs the **Hamsukhan Connector** plugin; never needs a Hamsukhan login |
| **Super admin** | You, the platform owner | Logs in at `/login` with an admin account → lands on `/admin` |

---

## 2. Modules

| # | Module | What it does |
|---|---|---|
| 1 | **Accounts & Auth** | Sign up (terms required), log in, remember me, forgot/reset password, log out, profile, avatar, email change, password change, delete account |
| 2 | **Dashboard (Overview)** | Calls today / this month / average length, balance, 7/14/30-day call chart, busiest hour, completion rate, bookings this month, "why people call", recent calls, setup checklist |
| 3 | **Bot Studio** | Greeting, language, instructions (prompt) with templates, max call length, end-of-call message, **what the bot must collect** (custom fields) |
| 4 | **Voices** | Record your voice in the browser (≥30 s, consent required) → cloned with ElevenLabs; up to 5 voices; preset voices; preview; delete |
| 5 | **Knowledge** | Upload menu / price list / FAQ files (PDF, DOCX, TXT, CSV, MD) + "quick facts" the bot must know |
| 6 | **Website widget** | Call button for the business's website: position, size, colours, text for idle / connecting / on call / error; live preview; one-line embed code; "installed" detection |
| 7 | **Calls engine** | Starts web calls through Vapi (balance check + max length), receives the end-of-call report, stores transcript, summary, recording, outcome, cost |
| 8 | **Call Logs** | Search transcripts, filter by date and status, pagination, call details (transcript, summary, recording player), CSV export |
| 9 | **Bookings & Orders** | Everything the bot captured: customer, phone, request, custom fields (items, address, date…), type (booking / order / callback), status new → confirmed → done, link to the call, "sent to your software" status |
| 10 | **Integrations (Plugin)** | Connectors that push every booking/order into the business's own software. See section 4 |
| 11 | **Wallet & Billing** | Prepaid balance in USD (PKR shown), per-second billing, ledger of every change, low-balance warning, **manual top-ups** via JazzCash / Easypaisa / bank with screenshot |
| 12 | **Super admin — Accounts** | List/search all customers, account page, **Login as** (impersonation), suspend / re-activate, adjust balance |
| 13 | **Super admin — Payments** | Approve / reject manual top-ups (view screenshot, reason required for reject) |
| 14 | **Super admin — Settings** | Site settings, SEO, billing & payment accounts, **API keys (Vapi, ElevenLabs, OpenAI)**, plugin rules + all connectors, cron & system health |
| 15 | **Audit log** | Every super-admin action (login-as, suspend, balance change, payment review, settings change, connector pause) with who, when, what |
| 16 | **Test your bot** | Customer talks to their own bot from the dashboard (real call with Vapi; simulated sample call until keys are set) |

---

## 3. What each role can do

### 3.1 Website visitor (caller)
- Sees the call button on the business website (`widget.js`).
- Clicks it → talks to the bot in the owner's cloned voice.
- Can ask questions (hours, prices, address — from the knowledge files), book an appointment, place an order, ask for a callback.
- Cannot call if the business has no balance or is suspended (the button shows an error state).

### 3.2 Business owner (customer)

| Area | Can do |
|---|---|
| Account | Sign up, log in/out, reset password, edit name/email/timezone, upload photo, change password, delete account |
| Overview | See today's and this month's numbers, call chart, why people call, recent calls; finish the setup checklist |
| Bot Studio → Configure | Write greeting + instructions (or load a template: receptionist, restaurant, clinic, salon); choose language and max call length; **choose the fields the bot must collect** (e.g. items, address, doctor, date) |
| Bot Studio → Voice | Clone own voice (record in browser), pick a preset voice, preview, delete |
| Bot Studio → Knowledge | Upload files, write quick facts |
| Bot Studio → Widget / Embed | Style the call button, copy the one-line script for their website |
| Test your bot | Talk to the bot from the dashboard |
| Call Logs | Search, filter, open transcripts, play recordings, export CSV |
| Bookings | See bookings/orders with all captured details, change status, open the call, see whether it reached their software |
| Integrations | Add/edit/pause/delete connectors, send a test, see the delivery log, retry, rotate the secret, download the plugin |
| Billing | See balance and ledger, submit a top-up (JazzCash / Easypaisa / bank + transaction ID + screenshot), see review status |

Cannot: see other businesses' data (every query is scoped to their own bot), approve their own payments, change prices, or see API keys.

### 3.3 Business's developer
- Downloads the **Hamsukhan Connector** (from Integrations, or receives it from the owner).
- Installs it in their Laravel or plain-PHP software, pastes the signing secret, writes one small handler that saves the booking/order in their database.
- Gets every booking/order automatically, verified and de-duplicated.

### 3.4 Super admin

| Area | Can do |
|---|---|
| Overview | Total accounts, new this month, suspended, calls today, minutes this month, top-ups this month, usage charged, money held in wallets, pending payments, recent admin activity |
| Accounts | Search/filter all customers; open an account (bot, voice, calls, bookings, connectors, ledger, admin history) |
| Login as | Enter any customer's account to help them (blue banner + "Return to admin"); cannot impersonate other admins |
| Suspend / Re-activate | Reason required; suspended users are signed out, cannot log in, and their bot stops taking calls |
| Adjust balance | Add or deduct (with reason); goes through the wallet ledger; cannot make a balance negative |
| Payments | Approve (credits the wallet exactly once) or reject (with a reason the customer sees) manual top-ups; view screenshots |
| Settings → Site | Site name, support email/phone, terms & privacy links, announcement banner for all customers, turn sign-ups on/off |
| Settings → SEO | Meta title, description, keywords, share image, Google Analytics ID, allow/deny search-engine indexing, Google preview |
| Settings → Billing | Price per minute, PKR rate, minimum top-up, low-balance threshold, JazzCash / Easypaisa / bank account details |
| Settings → API keys | Vapi private/public key, webhook secret, model; ElevenLabs key and voice model; OpenAI key — stored encrypted, shown only as `••••last4`, "Test connection" buttons, Vapi server URL to copy |
| Settings → Plugins | Turn connectors on/off for the whole platform, allow/deny form connectors, max connectors per account, see and pause **every** connector, download the package, delivery stats |
| Settings → Cron & system | Is cron running (heartbeat), the cron line to paste in cPanel, "Run now", health checks (debug mode, HTTPS, storage link, keys, webhook secret, mail) |
| Audit log | Everything above is recorded |

---

## 4. The plugin (Hamsukhan Connector) — how it works

### 4.1 The idea
The bot captures a booking or order on a call. Hamsukhan then **sends it to the business's own software automatically**,
so the restaurant's POS gets the order, the clinic's system gets the appointment — no copying by hand.

### 4.2 The flow

```
1. Caller talks to the bot on the website
2. Call ends → Vapi sends the call report to Hamsukhan
3. Hamsukhan stores the call + transcript, creates the booking/order
   (with the custom fields: items, address, date, …) and charges the wallet
4. For every active connector that listens to this event:
       Hamsukhan ──signed POST──►  business's software
                                   (Connector plugin verifies the signature,
                                    calls the business's handler, which saves it)
                 ◄── {"ok": true, "reference": "ORD-1042"}
5. The booking in Hamsukhan shows "Sent to your software · ORD-1042"
6. If their server was down: retry after 1 min, 5 min, 30 min, 2 h, then marked failed
   (owner can press "Retry now"; super admin sees failures under Settings → Plugins)
```

### 4.3 Two kinds of connector

| | **Webhook (JSON)** | **Form fields** |
|---|---|---|
| For | Software you or a developer can change (Laravel, PHP, anything that accepts JSON) | Software you can't change but that has an HTML order/booking form |
| What is sent | The full event as JSON, signed | Only the fields you map, as a normal form submission |
| Setup | Install the Hamsukhan Connector, paste the secret, write a handler | In Hamsukhan: map "their form field name" ← "our value" (or fixed text) |
| Best when | You want reliability and the record id back | The form accepts posts without a login |

### 4.4 Events
`booking.created` · `order.created` · `callback.created` — each connector chooses which ones it receives.

### 4.5 Security
- Every delivery is signed: `Hamsukhan-Signature: t=<time>,v1=HMAC-SHA256(secret, "<time>.<body>")`.
  The plugin rejects anything unsigned, tampered with, or older than 5 minutes.
- Each delivery has a unique id (`Hamsukhan-Delivery`); a retry reuses it, so the plugin never saves the same order twice.
- Secrets are encrypted in Hamsukhan's database and can be rotated.
- Hamsukhan refuses to send to private/internal network addresses in production.

### 4.6 Installing it (for the developer)

**Laravel**
1. Copy `hamsukhan-connector` into the project and `composer require hamsukhan/connector:@dev` (path repository).
2. `.env`: `HAMSUKHAN_WEBHOOK_SECRET=whsec_…` and `HAMSUKHAN_HANDLER=App\Hamsukhan\SaveOrder`.
3. Write the handler:

```php
class SaveOrder implements \Hamsukhan\Connector\Contracts\EventHandler
{
    public function handle(\Hamsukhan\Connector\Event $event): ?string
    {
        $order = Order::create([
            'customer' => $event->customerName(),
            'phone'    => $event->phone(),
            'items'    => $event->field('items'),
            'address'  => $event->field('address'),
        ]);
        return 'ORD-'.$order->id;   // shown next to the booking in Hamsukhan
    }
}
```
4. In Hamsukhan → Integrations → Add connector → Webhook → `https://their-app.com/hamsukhan/webhook` → **Send test**.

**Plain PHP** — one file:
```php
Hamsukhan\Connector\Receiver::run('whsec_…', function ($event) use ($pdo) {
    $pdo->prepare('INSERT INTO orders (customer, phone, items) VALUES (?,?,?)')
        ->execute([$event->customerName(), $event->phone(), $event->field('items')]);
    return 'ORD-'.$pdo->lastInsertId();
});
```

### 4.7 Coming next: live answers during the call
Designed, to be built once Vapi keys are live (`docs/LIVE_CALL_ACTIONS.md`): during the call the bot asks the business's software
"is Saturday 11am free?" or "create this order" and tells the caller the real answer and order number.

---

## 5. What's real vs. simulated today

| Part | Status |
|---|---|
| Everything in the dashboard, billing, bookings, connectors, plugin, super admin, settings | Real, working, tested |
| Voice calls and voice cloning | **Simulated** until Vapi + ElevenLabs keys are entered (Settings → API keys). Test calls replay a sample conversation through the real billing/booking/connector path |
| Online card payments | Not used (company not registered). Manual JazzCash / Easypaisa / bank top-ups with admin approval |
| Live answers during the call | Designed, not built |
