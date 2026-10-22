# Hamsukhan — business logic

The rules the code enforces. Each rule names where it lives; most have a feature test in `tests/Feature`.

## 1. Accounts
- Sign up needs name, unique email, password (8+ chars, confirmed) and **accepted terms** (stored as `terms_accepted_at`).
  → `AuthController@register`
- Every customer account gets **one bot** at signup with a default greeting, prompt template and preset voice,
  and its Vapi assistant is created right away. → `BotProvisioner`
- An account without a bot gets one on its next visit; an **admin-only** account (no bot) is sent to the payments queue.
  → `EnsureBot` middleware
- Forgot password always answers "if an account exists, a link is on its way" — the endpoint can't be used to find emails.
- Changing the email clears `email_verified_at`.
- Deleting an account needs the word `DELETE` **and** the password. It deletes cloned voices at ElevenLabs, all stored files,
  and (by cascade) the bot, calls, bookings and wallet ledger. → `SettingsController@destroy`
- Default timezone `Asia/Karachi`; "today" and "this month" on the dashboard use the user's timezone.

## 2. Bot configuration
- Greeting (≤300), language (English/Urdu/Arabic/Hindi/Spanish), instructions/prompt (≤4000), end-of-call message,
  max call length **5 / 10 / 15 min**. → `BotStudioController@updateConfig`
- The prompt sent to the LLM = instructions + "Facts you must know" (quick facts). → `Bot::systemPrompt()`
- **Our database is the source of truth.** Every save re-syncs the Vapi assistant. If the sync fails the save is kept
  and the user is told it will retry on the next save. → `BotStudioController@save`
- Widget appearance (position, offset, size, 4 states: idle / connecting / on call / error, each with title, subtitle,
  `#RRGGBB` colour, optional icon URL) is validated and saved; it doesn't need a Vapi sync.

## 3. Voices
- Clone = browser recording (MediaRecorder) uploaded with a name. Rules: **≥30 s** recording, **consent checkbox**
  (stored as `consent_at`), at most **5 cloned voices** per account. → `VoiceController@store`
- The first cloned voice becomes the bot's voice automatically.
- Choosing a voice: either one of your **ready** cloned voices or a preset. You can't pick another user's voice.
- Deleting the active voice falls back to your newest other voice, or the first preset. The voice is also deleted at ElevenLabs.

## 4. Knowledge
- Upload PDF/DOCX/TXT/CSV/MD, ≤10 MB each, ≤10 per upload. Stored per bot under `storage/app/private/knowledge/{bot}`.
- Quick facts (≤2000 chars) go straight into the system prompt.
- Pushing files into Vapi's knowledge base is the next step once the Vapi key is set (`KnowledgeFileController@store`).

## 5. Calls (the core flow)
```
visitor clicks widget ─► POST /api/widget/{key}/start
                          · bot must be active
                          · maxDurationSeconds = min(bot max length, balance ÷ per-second rate)
                          · < 30 affordable seconds ⇒ 402 "no_balance" (widget shows an error, call never starts)
                       ─► browser starts the Vapi web call (public key + assistant id + overrides)
Vapi ⇄ OpenAI ⇄ ElevenLabs (live)
call ends            ─► Vapi POSTs "end-of-call-report" to /api/webhooks/vapi
                          · x-vapi-secret must match (required in production)
                          · CallIngestService: Call + transcript + optional Booking, then WalletService charges it
```
- **Idempotent:** `calls.vapi_call_id` is unique, and `wallet_transactions.call_id` is unique → a retried webhook never
  duplicates or double-charges a call.
- Status: `failed` if duration is 0 or the end reason is an error; `dropped` if the caller never spoke; else `completed`.
- Title / outcome / booking come from Vapi's structured-data analysis (schema defined in `VapiService::assistantPayload`).
  Outcome must be one of: Booking, Order, Enquiry, Pricing, Support, No outcome.
- A booking is created only when the analysis has a customer name **and** a request.
- System-prompt messages are never stored in the transcript.
- "Test your bot" calls are flagged `is_test`, are charged like real calls, and are excluded from dashboard counts.
  Without a Vapi key the test call is **simulated**: a sample conversation goes through the exact same ingest + billing path.
- The widget marks the bot as **installed** the first time its config is loaded from a domain other than ours.

## 6. Money
- Wallet in **USD** (Vapi bills in USD). Price: **$0.12 / minute, billed per second**: `cost = seconds × rate ÷ 60`, 4 decimals.
- **Only `WalletService` changes a balance.** Each change locks the user row, updates `users.balance` and writes one
  `wallet_transactions` row with `balance_after`, in a single DB transaction. The ledger always sums to the balance.
- Call charges never take the balance below zero (they're clamped); the max-duration cap keeps that gap tiny.
  Manual debits that would overdraw are refused.
- Low-balance warning below **$5**.

## 7. Payments (manual top-ups — no gateway yet)
- Why: the company isn't registered, so Stripe/PayPal aren't available. Later: JazzCash/Easypaisa merchant (CNIC + NTN)
  or Safepay, added as a new payment method without touching the wallet logic.
- Customer flow: choose amount (min **$5**, max $1000) → we show the PKR amount (`HAMSUKHAN_PKR_PER_USD`, default 280)
  and our JazzCash / Easypaisa / bank account → customer pays in their app → submits **transaction ID + screenshot**.
  At most 3 pending top-ups per customer. Nothing is credited yet. → `BillingController@storeTopUp`
- Admin flow (`/admin/top-ups`): check the payment in the real account, then
  - **Approve** → wallet credited once (row lock + status check; approving twice is refused), reviewer + time recorded.
  - **Reject** → a reason is required and is shown to the customer.
  → `Admin\TopUpController`, `WalletService::approveTopUp / rejectTopUp`

## 8. Bookings
- Created automatically from calls (see §5). Status flow **new → confirmed → done** (any order allowed, owner only).
- Sidebar badge = number of `new` bookings.

## 9. Dashboard numbers
- Calls today / yesterday / this month / last month (non-test calls, user's timezone), average length of completed calls,
  completion %, bookings this month, busiest hour (last 30 days), calls per day (30 days), outcome breakdown (this month).
- Setup checklist: prompt edited away from the template · a cloned voice in use · widget changed from default ·
  knowledge file or quick facts · widget detected on a live site.

## 10. What the bot collects (capture fields)
- Always: customer name, phone, request, preferred time (columns on `bookings`).
- Plus up to **12 custom fields** per bot (`bots.capture_fields`): label, type (text/number/date/time/list),
  "must ask", optional hint. Presets: restaurant order, clinic, salon. → `CaptureFields`, `BotStudioController@updateCaptureFields`
- Keys are slugged from the label; keys that clash with the standard fields are dropped.
- They become (a) a line in the system prompt listing what the bot must ask for, and (b) the `booking.details`
  schema in Vapi's structured-data extraction. Values come back on the end-of-call report and are stored in
  `bookings.details` (unknown keys are ignored; lists are stored as "a, b, c").
- Booking type: `order` / `booking` / `callback` — from the analysis, else derived from the outcome (Order → order, Support → callback).

## 11. Connectors (send bookings/orders into the customer's software)
- Up to **5 connectors** per bot. Kinds:
  - **Webhook (JSON):** full event, for their own code or our `hamsukhan/connector` package.
  - **Form fields:** POSTs `application/x-www-form-urlencoded` to an existing form's action URL, using a mapping
    (their field name ← one of our values, or fixed text).
- Each connector subscribes to events: `booking.created`, `order.created`, `callback.created`. Paused connectors get nothing.
- Every delivery is signed: `Hamsukhan-Signature: t=<unix>,v1=HMAC-SHA256(secret, "<t>.<body>")`, plus `Hamsukhan-Event`
  and `Hamsukhan-Delivery` (unique id; retries reuse it so receivers can de-duplicate). Secrets are encrypted at rest and
  can be rotated.
- First attempt runs right after the call is stored (never blocks Vapi's webhook). Result rules:
  2xx = delivered (their JSON `reference`/`id` is saved and shown next to the booking) · 4xx except 408/429 = failed, no retry ·
  timeouts/5xx/408/429 = retry after **1 min, 5 min, 30 min, 2 h**, then failed. Retries run from
  `php artisan hamsukhan:deliver-integrations` (scheduled every minute). Manual "Retry now" and "Send test" exist.
- URLs must be http(s); production requires https and refuses private/loopback addresses (SSRF guard). → `UrlGuard`
- The package (`packages/hamsukhan-connector`, downloadable as a zip) verifies the signature, de-duplicates by delivery id,
  and calls the customer's `EventHandler`, returning their record id. Works in Laravel (auto route `/hamsukhan/webhook`)
  and plain PHP (`Receiver::run`).
- During-the-call lookups/writes: designed in `docs/LIVE_CALL_ACTIONS.md`, built once Vapi keys exist.

## 12. Super admin
- `users.is_admin` = super admin. Admin-only accounts have no bot and land on `/admin`.
- **Accounts:** list/search (name, email, business), filter active/suspended, per-account page with bot, calls, ledger.
- **Login as** (impersonation): admin → customer session with a banner and "Return to admin"; can't impersonate admins
  or nest impersonations. Everything done while impersonating happens on the customer's account.
- **Suspend** (reason required): signs them out on their next request, blocks login, sets the bot inactive so the
  widget refuses calls. **Re-activate** reverses it.
- **Adjust balance** (± up to $1000, reason required): goes through `WalletService::adjust` (can't overdraw).
- **Payments:** approve/reject manual top-ups (§7).
- **Audit log:** every impersonation, suspend/activate, adjustment and top-up review is stored in `admin_actions`
  with admin, target, details and IP, shown on the overview and account pages.
- **Platform settings** — see §13.

## 13. Platform settings (super admin)
- Stored in the `settings` table; on every boot `PlatformSettings::apply()` copies them over `config('hamsukhan.*')`,
  so they win over `.env`. Empty value = use the default. Cached; saving clears the cache.
- Groups: **Site** (name, support email/phone, terms/privacy links, announcement banner, allow sign-ups) ·
  **SEO** (meta title/description/keywords, share image, index on/off, Google Analytics id — format-validated) ·
  **Billing** (rate per minute, PKR rate, minimum top-up, low-balance threshold, JazzCash/Easypaisa/bank details) ·
  **API keys** (Vapi private/public key, webhook secret, model; ElevenLabs key + model; OpenAI key) ·
  **Plugins** (connectors on/off platform-wide, allow form connectors, max per account; pause any customer's connector) ·
  **Cron & system** (heartbeat, cron line, "Run now", health checks).
- Secrets: encrypted at rest, never sent to the browser (only `••••last4` + whether it comes from the admin or `.env`);
  a blank secret field on save keeps the old value; "Remove" falls back to `.env` or simulated mode.
- Setting a Vapi/ElevenLabs key takes the platform out of simulated mode immediately (no deploy needed).
- "Test connection" makes a read-only call (Vapi: list assistants; ElevenLabs: `/v1/user`, also warns if the plan can't clone).
- Sign-ups off ⇒ `/register` shows "closed" and the POST is refused (403). Connectors off ⇒ no new connectors and no deliveries.
- SEO tags apply to the public pages; logged-in app pages are always `noindex`.
- Cron heartbeat: the scheduler writes `hamsukhan:cron_last_run` every minute; healthy if seen in the last 3 minutes.
- Every settings change is audited with the group and the changed keys — never the values.

## 14. Security rules
- Provider secrets stay on the server. The browser only ever gets the Vapi **public** key and the assistant id.
- Public widget endpoints return appearance only (no prompt, no keys), are rate-limited, and allow CORS.
- Webhook: secret header check; production refuses to run without a secret.
- Customer data is always read through the signed-in user's bot (scoped queries); other users' ids return 404.
- Payment proofs, voice samples and knowledge files are on the private disk; proofs are served to admins only.
- Login/registration/forgot-password are throttled.

## Launch changes (2026-10-22)
- **Not billed:** landing-page demo calls (`metadata.isDemo`) and failed connections (`status = failed`) get cost 0.
- **Demo:** `POST /api/demo/start` talks to the bot set in Admin → Settings → Site → "Live demo bot". Max 90 s, 5 starts per IP per hour; demo calls are pruned after 7 days (`prune-demo-calls`).
- **Callback request:** `POST /api/widget/{key}/callback` (widget + landing) creates a `callback` booking with `source = widget`; phones are normalised to `+92…` by `App\Support\Phone`.
- **Vapi sync never blocks signup:** `VapiService::trySync()` stores the reason in `bots.sync_error` (shown in Bot Studio with a Retry button). A voice made in demo mode (`sim_` id) is cloned on ElevenLabs automatically before the next sync.
- **Preset voices** map to real ElevenLabs premade voice ids (`config/hamsukhan.php`). Urdu / mixed calls use ElevenLabs Scribe as transcriber (Deepgram has no Urdu).
- **Safety rules** (`Bot::SAFETY_RULES`) are appended server-side to every prompt.
- **Admin → ElevenLabs voices:** lists voices in the ElevenLabs account and assigns one to a customer (`provider = elevenlabs_import`, never deleted on ElevenLabs when the customer removes it).
