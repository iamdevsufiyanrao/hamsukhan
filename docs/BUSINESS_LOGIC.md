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
  and (by cascade) every store's bot, calls, bookings and payments. → `SettingsController@destroy`
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
                          · maxDurationSeconds = min(bot max length, fair-use minutes left this month)
                          · no active plan / store over the plan / fair use used up ⇒ 402 with the reason (call never starts)
                       ─► browser starts the Vapi web call (public key + assistant id + overrides)
Vapi ⇄ OpenAI ⇄ ElevenLabs (live)
call ends            ─► Vapi POSTs "end-of-call-report" to /api/webhooks/vapi
                          · x-vapi-secret must match (required in production)
                          · CallIngestService: Call + transcript + optional Booking (no per-call charge)
```
- **Idempotent:** `calls.vapi_call_id` is unique → a retried webhook never duplicates a call.
- Status: `failed` if duration is 0 or the end reason is an error; `dropped` if the caller never spoke; else `completed`.
- Title / outcome / booking come from Vapi's structured-data analysis (schema defined in `VapiService::assistantPayload`).
  Outcome must be one of: Booking, Order, Enquiry, Pricing, Support, No outcome.
- A booking is created only when the analysis has a customer name **and** a request.
- System-prompt messages are never stored in the transcript.
- "Test your bot" calls are flagged `is_test`, need an active plan like real calls, and are excluded from dashboard counts.
  Without a Vapi key the test call is **simulated**: a sample conversation goes through the exact same ingest path.
- The widget marks the bot as **installed** the first time its config is loaded from a domain other than ours.

## 6. Plans (monthly subscription)
- Two plans, prices in PKR (super admin can change them in Settings → Billing):
  **Basic Rs 3,000/month: 1 store, 1 user** · **Business Rs 25,000/month: 5 stores, 5 users** (owner included). → `config/hamsukhan.php` `plans`
- Calls are **unlimited** while the plan is paid. Each plan has a hidden **fair-use limit** in minutes per account per calendar month
  (all its stores together, failed connections don't count; 0 = no limit). Near the limit the call's max length shrinks; at the limit
  calls stop until the 1st and the owner gets one alert. → `SubscriptionService::blockReason / maxCallSeconds`
- A call (website, test, AI callback, reminder, campaign) only starts with an active plan. Reasons: `no_plan`, `store_limit`, `fair_use`.
- **Stores:** each store is a bot (own prompt, voice, widget key, calls, bookings, connectors). `users.current_bot_id` = the store
  the user has open; `User::bot()` returns it (else the oldest). Everyone in the account can switch; only the owner adds/removes
  (type DELETE; never the last store). After a downgrade the **oldest** N stores keep working, the rest refuse calls.
- **Users:** owner + team members ≤ the plan's users. Without an active plan the Basic limits apply.
- `calls.cost` = what Vapi charged us (from the end-of-call report), shown only to the super admin.
- Renewal reminder 3 days before the end (daily cron, once per period). Optional free trial: `trial_days` (default 0).
- The old wallet (`users.balance`, `wallet_transactions`) is no longer read or written; the data stays in the DB as history.

## 7. Payments (manual — no gateway yet)
- Why: the company isn't registered, so Stripe/PayPal aren't available.
- Owner flow: pick plan + months (1/3/6/12) → we show the PKR total (price × months, computed on the server) and our
  JazzCash / Easypaisa / bank account → owner pays → submits **transaction ID + screenshot**. At most 3 pending. → `BillingController@storePayment`
- Admin flow (`/admin/top-ups`): **Approve** → plan activated once (row lock + status check). Same plan while still paid is
  **extended from its end date**; a different or expired plan starts today. **Reject** needs a reason, shown to the owner.
  → `SubscriptionService::approvePayment / rejectPayment / activate`
- The super admin can also set or end a plan by hand on the account page (reason required, audited as `set_plan`).

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
- **Set plan** (plan + 1–12 months, or end it now; reason required): goes through `SubscriptionService::activate`, audited as `set_plan`.
- **Payments:** approve/reject manual top-ups (§7).
- **Audit log:** every impersonation, suspend/activate, adjustment and top-up review is stored in `admin_actions`
  with admin, target, details and IP, shown on the overview and account pages.
- **Platform settings** — see §13.

## 13. Platform settings (super admin)
- Stored in the `settings` table; on every boot `PlatformSettings::apply()` copies them over `config('hamsukhan.*')`,
  so they win over `.env`. Empty value = use the default. Cached; saving clears the cache.
- Groups: **Site** (name, support email/phone, terms/privacy links, announcement banner, allow sign-ups) ·
  **SEO** (meta title/description/keywords, share image, index on/off, Google Analytics id — format-validated) ·
  **Billing** (Basic/Business price and fair-use minutes, trial days, JazzCash/Easypaisa/bank details) ·
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

## Pilot features (2026-10-22)
- **Owner alerts** (`NotificationService`): new lead (call or callback form; not for test calls), plan renewal (3 days before the end, once per paid period), fair-use limit reached (once per month), connector delivery failed for good, daily summary at 21:00 PKT (only on days with activity). Channels: email (`notify_email`) and WhatsApp Cloud API (`notify_whatsapp` + `whatsapp_number`, needs platform token). `notification_logs` unique (dedupe_key, channel) = never sent twice; a failed alert is logged, never breaks a call.
- **Monthly spending cap** (`users.monthly_cap`, USD, entered in PKR): `maxAffordableSeconds` also caps by what is left this month; at zero the widget answers 402 `cap_reached`.
- **Opening hours + holidays** (`bots.business_hours`, `bots.holidays`, `BusinessHours`): written into the prompt; calls outside them get `calls.after_hours`.
- **Allowed websites** (`bots.allowed_domains`): widget config/start/callback refuse other origins (403). Our own app host and localhost are always allowed.
- **Knowledge gaps**: Vapi structured data `unansweredQuestions` → `calls.unanswered` → Insights page.
- **Lead statuses**: new, contacted, confirmed (booked), done (won), lost, spam.
- **Email verification**: always sent on signup; enforced only when Admin → Site → "Require email verification" is on. Accounts that existed before 2026-10-22 were marked verified.
- **Ops**: every response has `X-Request-Id` (also in log context); unexpected production errors are emailed to `alert_email` (same error once per 30 min).

## Live booking, Google Calendar, two-step login (2026-10-22)
- **Live booking** (`bots.live_booking`, `slot_minutes`; needs opening hours): Vapi gets two function tools, `check_availability` and `book_appointment`, answered at the Vapi webhook (`tool-calls`). Free slots = opening hours − holidays − bookings not lost/spam − Google busy times; no slot starts within 30 minutes. Booking re-checks the slot under a lock on the bot row (no double booking); same call + same time twice returns the same booking. Live bookings carry `vapi_call_id`; the end-of-call report links them (no duplicate, no second alert or connector delivery).
- **Google Calendar** (`google_calendars`, tokens encrypted): owner connects in Integrations (OAuth, state-checked). Bookings made live become events in the primary calendar; marking one lost/spam deletes the event. If Google fails, slots still come from our own bookings and the error shows on Integrations.
- **Two-step login** (`users.two_factor_*`): TOTP app + 8 single-use recovery codes (stored hashed). The login asks for the code before signing in; 5 tries/minute per pending login. "Log out other devices" deletes other DB sessions and rotates the remember token.
- **Audio**: only one sound plays at a time in the app (`resources/js/lib/audioFocus.ts`).

## Phone calls, transfer, AI callbacks, reminders (2026-10-22)
- **Phone numbers**: bought/imported in Vapi (Twilio, Telnyx, Vonage, SIP; Vapi sells no +92 numbers). Super admin → Phone numbers assigns one to a bot (`bots.vapi_phone_number_id`); Vapi then rings that bot's assistant for inbound calls. Calls get `direction` (web/inbound/outbound) and `customer_number` from the report.
- **Transfer to a person** (`bots.transfer_number`): Vapi `transferCall` tool; phone calls only. On website calls the bot takes a callback.
- **AI callback**: Bookings → "AI call back" makes an outbound call (bot's number, else `vapi.phone_number_id`). Needs an active plan (and ≥60 s of fair use left); not twice within 10 minutes; a `new` lead becomes `contacted`. Covered by the plan like any call.
- **Reminder calls** (`bots.reminder_hours` 2/4/24): every 15 minutes, confirmed bookings starting inside the window are called once (`bookings.reminder_called_at` is set before dialling).

## Live calls, team, history, campaigns (2026-10-22)
- **Live now**: assistants send `status-update`; `in-progress` stores the call's Vapi monitor URLs (`live_calls`, encrypted), `ended` / end-of-call report removes it (stale after 2 h). Dashboard and Call Logs poll `/live-calls`; Listen plays the listen WebSocket as 16-bit PCM (rate/channels in Admin → API keys, not documented by Vapi). Say / Tell the bot (system message, not spoken) / End go server-side to the control URL (only `*.vapi.ai` hosts). Viewers can't listen or control.
- **Team** (`users.owner_id`, `team_role`): owner adds members up to the plan's users (Basic: none, Business: 4); the password-reset email is the invite. Members use the owner's stores, voices, calendar and plan (`User::account()`) and can switch stores; `EnsureTeamPermission`: admin = all but billing and team, operator = view + leads/AI callback/live calls/test calls, viewer = view only. Owner suspended ⇒ members locked out. A member deleting "their account" deletes only their login. Admin's account list hides members.
- **Knowledge history** (`knowledge_versions`, last 50): greeting/instructions/quick facts are snapshotted before every change; restore keeps the current state as a version too.
- **Campaigns** (`campaigns`, `campaign_contacts`): owner pastes up to 500 contacts and must confirm consent. Every 5 minutes running campaigns call the next contacts (`per_run` at a time) only inside opening hours (10:00–20:00 PKT without hours). Plan inactive or fair use used up ⇒ campaign pauses. The end-of-call report marks the contact done/failed (scoped to the bot).
