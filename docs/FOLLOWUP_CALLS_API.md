# Follow-up calls API (design, not built yet)

**Goal:** a business's own system (salon software, clinic system, POS, CRM) asks Hamsukhan to phone one of
its customers. Hamsukhan places the call **from our number, in the owner's cloned voice**, talks to the customer
(confirm, reschedule, cancel, review, rebook) and sends the result back to the business's system.

The business does not need its own phone line or AI setup. It only calls our API.

## 1. Who does what

```
Business system ──POST /api/v1/follow-ups──► Hamsukhan   (API key, wallet check, schedule)
                                               │ at the scheduled time, inside calling hours
                                               ▼
                         Vapi POST /call  (our imported phone number → customer's phone)
                         Vapi ⇄ OpenAI ⇄ ElevenLabs (owner's cloned voice)
                                               │ call ends
                                               ▼
                         Vapi end-of-call report → /api/webhooks/vapi (existing)
                                               │ outcome + transcript + cost
                                               ▼
Business system ◄──signed POST "followup.completed"── Hamsukhan   (same signature as connectors)
```

## 2. API (for the business's developer)

Auth: `Authorization: Bearer hsk_live_…` (per account, created in Settings → API keys, stored hashed, revocable).
Idempotency: `Idempotency-Key` header; repeating a request with the same key returns the first result.

### Create
`POST /api/v1/follow-ups`
```json
{
  "phone": "+15551234567",
  "name": "Ayesha",
  "purpose": "appointment_reminder",
  "call_at": "2026-10-20T15:00:00+05:00",
  "context": { "service": "Haircut + blow dry", "date": "2026-10-21", "time": "17:00", "staff": "Hina" },
  "instructions": "If she wants another time, offer Wednesday 2pm or 5pm.",
  "reference": "APT-1042",
  "consent": true
}
```
- `purpose`: `appointment_reminder`, `no_show`, `review_request`, `rebook`, `payment_reminder`, `order_update`, `custom`.
  Each purpose has a ready script; `instructions` adds to it.
- `call_at` optional (default: now). Calls only go out inside the business's calling hours (default 10:00 to 20:00 in
  its timezone); a time outside is moved to the next allowed slot.
- `consent: true` is required: the business confirms the customer agreed to be contacted.
- Response `202`: `{ "id": "fu_…", "status": "scheduled", "call_at": "…", "estimated_cost": 0.40 }`.
- Refused: no balance (`402`), number on the do-not-call list (`422`), more than N pending follow-ups (`429`).

### Read / cancel / list
`GET /api/v1/follow-ups/{id}` · `DELETE /api/v1/follow-ups/{id}` (only while scheduled) · `GET /api/v1/follow-ups?status=&reference=`

### Result (webhook to the business)
Event `followup.completed`, signed with `Hamsukhan-Signature` like connectors:
```json
{
  "id": "fu_…", "reference": "APT-1042",
  "outcome": "rescheduled",
  "new_time": "2026-10-22T14:00:00+05:00",
  "summary": "Ayesha can't make Tuesday, moved to Wednesday 2pm.",
  "attempts": 1, "duration_seconds": 64, "cost": 0.21,
  "recording_url": "…", "transcript": [ … ]
}
```
Outcomes: `confirmed`, `rescheduled`, `cancelled`, `callback_requested`, `opted_out`, `no_answer`, `voicemail`, `busy`, `failed`.

## 3. Rules
- **Retries:** `no_answer` / `busy` → retry after 1 h, then 3 h (max 3 attempts, all inside calling hours). Voicemail → leave a
  short message once, no retry.
- **Opt-out:** if the customer says "don't call me again", the number goes on the business's do-not-call list.
- **Billing:** outbound minutes have their own rate (telephony costs more than web calls), billed per second through
  `WalletService`; an unanswered attempt is charged a small flat fee. A follow-up starts only if the balance covers the max length.
- **Max length:** 3 minutes by default.
- **Same bot, different job:** the call uses the business's voice and knowledge, with a follow-up script and the
  `context` values passed as Vapi `variableValues`.
- **No-code option:** businesses without software turn on rules in the dashboard ("call 24 h before every booking",
  "ask for a review 3 h after"), which create the same follow-ups from bookings the bot captured.

## 4. Telephony (needed before building)
- Vapi's free numbers are US-only and capped at about 10 outbound calls a day, so they are only for testing.
- Production: import a number from Twilio, Telnyx or Vonage into Vapi (`/phone-numbers/import`) and call with its
  `phoneNumberId`. Check per-minute termination rates to the target countries before setting our outbound price.
- Later add-on: a dedicated number per business so the caller ID is theirs.

## 5. To build
1. `api_keys` table + Settings UI; `follow_ups` table (status, attempts, outcome, call_id, context JSON).
2. `FollowUpController` (API) + validation + idempotency; `FollowUpService` (schedule, retry, calling hours).
3. Scheduler command `hamsukhan:place-follow-ups` (every minute) → `VapiService::outboundCall()`.
4. `CallIngestService`: link calls to follow-ups through call metadata, read the outcome from structured data.
5. `followup.completed` delivery reusing the connector signer + retries; package `FollowUpHandler`.
6. Dashboard page "Follow-ups" + rules for no-code businesses; tests for every rule above.
