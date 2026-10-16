# Live call actions — design (build after Vapi keys)

**Goal:** during the call, the bot talks to the customer's own software, so it can say
"Saturday 11am is free — booked, your number is APT-1042" instead of "someone will confirm".

Today (built): bookings/orders reach the customer's software **after** the call, via connectors.
This adds **during-the-call** lookups and writes. Same signing, same package.

## How it works

```
caller ─► Vapi ─(LLM decides to use a tool)─► POST /api/webhooks/vapi  {message.type: "tool-calls"}
                                                   │
                                     Hamsukhan finds the bot's "live" connector
                                                   │  signed POST, 4 s timeout
                                                   ▼
                                  customer's software  /hamsukhan/live
                                  (package: LiveHandler::answer($action, $args))
                                                   │  {"result": "Saturday 11am is free"}
                                                   ▼
                     Hamsukhan replies to Vapi {results: [{toolCallId, result}]} ─► bot says it
```

## Actions (tools given to the assistant)

| Tool | Args | Customer's software returns |
|---|---|---|
| `check_availability` | `date`, `time?`, `service?` | free slots, e.g. "11:00, 15:00" |
| `create_booking` | standard + capture fields | `reference` + sentence to say |
| `create_order` | standard + capture fields (items, address…) | `reference`, total, ETA |
| `lookup_order` | `reference` or `phone` | status sentence ("ready for pickup") |

Tools are added to the Vapi assistant only for bots that have a live connector, and only the actions that connector enables.

## Rules
- **Time budget:** the customer's endpoint must answer within **4 seconds**; otherwise Hamsukhan answers the tool call with
  "I'll confirm that by message shortly" and the booking still goes through the normal after-call connector.
- A booking created live is marked with the returned `reference`, so the after-call delivery is skipped (no duplicates).
- Same `Hamsukhan-Signature` header; payload `{type: "tool", action, args, call: {id}, bot: {id}}`.
- Live tool usage is logged per call (action, latency, result) and shown in the call drawer.
- Never let the customer's response text be read out unfiltered beyond 300 characters.

## To build (when keys arrive)
1. `integrations.kind = live` + `actions` column; UI on the Integrations page ("Live answers").
2. `VapiService::assistantPayload` → `model.tools` (function tools whose `server.url` is our webhook).
3. `VapiWebhookController` → handle `tool-calls`; `LiveActionService` does the signed 4 s call.
4. Package: `Hamsukhan\Connector\Contracts\LiveHandler` + route `POST /hamsukhan/live`; plain-PHP `Receiver::live()`.
5. Tests with faked Vapi tool-call payloads + a live call test against the local receiver.
