import { Head, Link } from '@inertiajs/react';
import { ReactNode, useEffect, useState } from 'react';
import Icon, { Logo } from '@/components/Icon';

type Props = { appUrl: string; site: string; supportEmail: string | null };

/* ---------- small building blocks ---------- */

function Code({ code, label }: { code: string; label?: string }) {
    const [copied, setCopied] = useState(false);
    const copy = () =>
        navigator.clipboard?.writeText(code).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    return (
        <div className="docs-code">
            <div className="docs-code-h">
                <span>{label ?? 'Code'}</span>
                <button type="button" onClick={copy}>
                    <Icon name={copied ? 'check' : 'copy'} className="i i-sm" />
                    {copied ? 'Copied' : 'Copy'}
                </button>
            </div>
            <pre className="code mono">{code}</pre>
        </div>
    );
}

/** One example per language; the chosen language is remembered for every tab group on the page. */
function Tabs({ items, group }: { items: { id: string; label: string; code: string; note?: ReactNode }[]; group: string }) {
    const key = `hs-docs-${group}`;
    const [on, setOn] = useState(() => {
        try {
            return localStorage.getItem(key) ?? items[0].id;
        } catch {
            return items[0].id;
        }
    });
    const cur = items.find((i) => i.id === on) ?? items[0];
    const pick = (id: string) => {
        setOn(id);
        try {
            localStorage.setItem(key, id);
        } catch {}
    };
    return (
        <div className="docs-tabs">
            <div className="docs-tab-row" role="tablist">
                {items.map((i) => (
                    <button key={i.id} type="button" role="tab" aria-selected={i.id === cur.id} className={i.id === cur.id ? 'on' : ''} onClick={() => pick(i.id)}>
                        {i.label}
                    </button>
                ))}
            </div>
            {cur.note && <p className="docs-note">{cur.note}</p>}
            <Code code={cur.code} label={cur.label} />
        </div>
    );
}

function Steps({ items }: { items: ReactNode[] }) {
    return (
        <ol className="docs-steps">
            {items.map((s, i) => (
                <li key={i}>
                    <b>{i + 1}</b>
                    <div>{s}</div>
                </li>
            ))}
        </ol>
    );
}

function Tip({ children, kind = 'tip' }: { children: ReactNode; kind?: 'tip' | 'warn' }) {
    return (
        <div className={'docs-tip ' + kind}>
            <Icon name={kind === 'warn' ? 'alert' : 'book'} className="i i-sm" />
            <div>{children}</div>
        </div>
    );
}

/* ---------- content ---------- */

const NAV: [string, string][] = [
    ['start', 'Quick start'],
    ['install', 'Put it on your website'],
    ['collect', 'What the bot collects'],
    ['bookings', 'Bookings & orders'],
    ['integrations', 'Send them to your software'],
    ['nocode', 'No-code options'],
    ['webhook', 'Webhook reference'],
    ['examples', 'Code by language'],
    ['security', 'Signature check'],
    ['testing', 'Test & troubleshoot'],
    ['faq', 'FAQ'],
];

const PAYLOAD = `{
  "event": "order.created",
  "id": "4a7c2e9f-1b3d-4c55-9a0e-6f1d2b7c8e10",
  "test": false,
  "created_at": "2026-11-03T15:04:11+00:00",
  "bot": { "id": 3, "business": "Karachi Grill" },
  "booking": {
    "id": 118,
    "type": "order",
    "status": "new",
    "customer_name": "Bilal Ahmed",
    "phone": "+923015551234",
    "request": "Delivery: Mixed BBQ platter, 1.5L Coke",
    "preferred_time": "ASAP",
    "details": {
      "order_type": "delivery",
      "items": "Mixed BBQ platter, 1.5L Coke",
      "address": "Flat 7B, Clifton Block 5"
    }
  },
  "call": {
    "id": 512,
    "started_at": "2026-11-03T15:02:35+00:00",
    "duration_seconds": 96,
    "summary": "Caller ordered a Mixed BBQ platter for delivery to Clifton."
  }
}`;

function examples(url: string) {
    return [
        {
            id: 'laravel',
            label: 'PHP · Laravel',
            note: (
                <>
                    Easiest: download our ready package (Integrations → <b>PHP / Laravel connector</b>). It checks the signature, skips duplicates and gives you a tidy <code>Event</code>. Point the connector at <code>https://your-app.com/hamsukhan/webhook</code>.
                </>
            ),
            code: `// 1) composer.json → "repositories": [{ "type": "path", "url": "packages/hamsukhan-connector" }]
//    composer require hamsukhan/connector:@dev
// 2) .env
//    HAMSUKHAN_WEBHOOK_SECRET=whsec_...          (Integrations → your connector)
//    HAMSUKHAN_HANDLER=App\\Hamsukhan\\SaveBooking

namespace App\\Hamsukhan;

use Hamsukhan\\Connector\\Contracts\\EventHandler;
use Hamsukhan\\Connector\\Event;

class SaveBooking implements EventHandler
{
    public function handle(Event $event): ?string
    {
        if ($event->isTest()) {
            return 'TEST-OK';                       // "Send test" button
        }

        if ($event->isOrder()) {
            $order = \\App\\Models\\Order::create([
                'customer' => $event->customerName(),
                'phone'    => $event->phone(),
                'items'    => $event->field('items'),
                'address'  => $event->field('address'),
                'source'   => 'hamsukhan',
            ]);
            return 'ORD-'.$order->id;               // shown next to the booking in Hamsukhan
        }

        $appt = \\App\\Models\\Appointment::create([
            'name'  => $event->customerName(),
            'phone' => $event->phone(),
            'when'  => $event->preferredTime(),
            'notes' => $event->request(),
        ]);
        return 'APT-'.$appt->id;
    }
}`,
        },
        {
            id: 'php',
            label: 'PHP (plain)',
            note: <>No framework needed. Upload this file next to your site and use its URL as the connector URL.</>,
            code: `<?php // hamsukhan-webhook.php
$secret = 'whsec_...';                                  // Integrations → your connector
$body   = file_get_contents('php://input');             // the RAW body, before any decoding
$header = $_SERVER['HTTP_HAMSUKHAN_SIGNATURE'] ?? '';

parse_str(str_replace(',', '&', $header), $sig);        // t=...&v1=...
$ok = isset($sig['t'], $sig['v1'])
    && abs(time() - (int) $sig['t']) <= 300
    && hash_equals(hash_hmac('sha256', $sig['t'].'.'.$body, $secret), $sig['v1']);
if (! $ok) { http_response_code(401); exit('bad signature'); }

$e = json_decode($body, true);
if ($e['test']) { echo json_encode(['ok' => true, 'reference' => 'TEST-OK']); exit; }

$pdo = new PDO('mysql:host=localhost;dbname=shop;charset=utf8mb4', 'user', 'pass');
// "id" is the delivery id: a retry sends the same one, so make it UNIQUE to never save twice.
$stmt = $pdo->prepare('INSERT IGNORE INTO leads (delivery_id, type, name, phone, request, preferred_time, details)
                       VALUES (?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    $e['id'], $e['booking']['type'], $e['booking']['customer_name'], $e['booking']['phone'],
    $e['booking']['request'], $e['booking']['preferred_time'], json_encode($e['booking']['details']),
]);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'reference' => 'LEAD-'.$pdo->lastInsertId()]);`,
        },
        {
            id: 'wordpress',
            label: 'WordPress / WooCommerce',
            note: (
                <>
                    Paste into your theme's <code>functions.php</code> (or a small plugin). Connector URL: <code>https://your-site.com/wp-json/hamsukhan/v1/webhook</code>. Orders become WooCommerce orders; bookings become a private note-style post.
                </>
            ),
            code: `add_action('rest_api_init', function () {
    register_rest_route('hamsukhan/v1', '/webhook', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',      // the signature below is the check
        'callback'            => 'hamsukhan_webhook',
    ]);
});

function hamsukhan_webhook(WP_REST_Request $req) {
    $secret = 'whsec_...';
    $body   = $req->get_body();
    parse_str(str_replace(',', '&', (string) $req->get_header('hamsukhan_signature')), $sig);
    $ok = isset($sig['t'], $sig['v1']) && abs(time() - (int) $sig['t']) <= 300
        && hash_equals(hash_hmac('sha256', $sig['t'].'.'.$body, $secret), $sig['v1']);
    if (! $ok) return new WP_REST_Response(['ok' => false], 401);

    $e = json_decode($body, true);
    if ($e['test']) return ['ok' => true, 'reference' => 'TEST-OK'];
    $b = $e['booking'];

    // Never save the same delivery twice (retries reuse the id).
    if (get_transient('hamsukhan_'.$e['id'])) return ['ok' => true];
    set_transient('hamsukhan_'.$e['id'], 1, DAY_IN_SECONDS);

    if ($b['type'] === 'order' && function_exists('wc_create_order')) {
        $order = wc_create_order();
        $order->set_billing_first_name($b['customer_name']);
        $order->set_billing_phone((string) $b['phone']);
        $order->set_billing_address_1($b['details']['address'] ?? '');
        $order->set_customer_note($b['request']);
        $order->add_order_note('From the Hamsukhan AI receptionist: '.$b['request']);
        $order->save();
        return ['ok' => true, 'reference' => 'WC-'.$order->get_id()];
    }

    $id = wp_insert_post([
        'post_type'    => 'post',
        'post_status'  => 'private',
        'post_title'   => ucfirst($b['type']).': '.$b['customer_name'].' ('.$b['phone'].')',
        'post_content' => $b['request']."\\nWhen: ".$b['preferred_time']."\\n".print_r($b['details'], true),
    ]);
    return ['ok' => true, 'reference' => 'WP-'.$id];
}`,
        },
        {
            id: 'node',
            label: 'Node.js · Express',
            code: `import express from 'express';
import crypto from 'node:crypto';

const app = express();
const SECRET = process.env.HAMSUKHAN_WEBHOOK_SECRET; // whsec_...

function verify(raw, header) {
  const parts = Object.fromEntries((header || '').split(',').map((p) => p.trim().split('=')));
  if (!parts.t || !parts.v1 || Math.abs(Date.now() / 1000 - Number(parts.t)) > 300) return false;
  const expected = crypto.createHmac('sha256', SECRET).update(\`\${parts.t}.\${raw}\`).digest('hex');
  return expected.length === parts.v1.length && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(parts.v1));
}

// express.raw: we need the exact bytes we were sent, not re-encoded JSON.
app.post('/hamsukhan/webhook', express.raw({ type: '*/*' }), async (req, res) => {
  const raw = req.body.toString('utf8');
  if (!verify(raw, req.get('Hamsukhan-Signature'))) return res.status(401).json({ ok: false });

  const e = JSON.parse(raw);
  if (e.test) return res.json({ ok: true, reference: 'TEST-OK' });

  const { type, customer_name, phone, request, preferred_time, details } = e.booking;
  // const row = await db.leads.upsert({ deliveryId: e.id, type, customer_name, phone, request, preferred_time, details });
  res.json({ ok: true, reference: \`LEAD-\${e.booking.id}\` });
});

app.listen(3000);`,
        },
        {
            id: 'next',
            label: 'Next.js',
            note: <>App Router route handler: <code>app/api/hamsukhan/route.ts</code>. Connector URL: <code>https://your-app.com/api/hamsukhan</code>.</>,
            code: `import crypto from 'node:crypto';

export async function POST(req: Request) {
  const raw = await req.text();
  const parts = Object.fromEntries((req.headers.get('hamsukhan-signature') ?? '').split(',').map((p) => p.trim().split('=')));
  const expected = crypto.createHmac('sha256', process.env.HAMSUKHAN_WEBHOOK_SECRET!).update(\`\${parts.t}.\${raw}\`).digest('hex');
  const fresh = Math.abs(Date.now() / 1000 - Number(parts.t)) <= 300;
  if (!fresh || !parts.v1 || expected.length !== parts.v1.length ||
      !crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(parts.v1))) {
    return Response.json({ ok: false }, { status: 401 });
  }

  const e = JSON.parse(raw);
  if (e.test) return Response.json({ ok: true, reference: 'TEST-OK' });

  // await prisma.lead.upsert({ where: { deliveryId: e.id }, create: { ... }, update: {} });
  return Response.json({ ok: true, reference: \`LEAD-\${e.booking.id}\` });
}`,
        },
        {
            id: 'python',
            label: 'Python · Flask',
            code: `import hashlib, hmac, json, os, time
from flask import Flask, request, jsonify

app = Flask(__name__)
SECRET = os.environ["HAMSUKHAN_WEBHOOK_SECRET"].encode()  # whsec_...

def verify(raw: bytes, header: str) -> bool:
    parts = dict(p.strip().split("=", 1) for p in header.split(",") if "=" in p)
    if "t" not in parts or "v1" not in parts or abs(time.time() - int(parts["t"])) > 300:
        return False
    expected = hmac.new(SECRET, parts["t"].encode() + b"." + raw, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, parts["v1"])

@app.post("/hamsukhan/webhook")
def hamsukhan_webhook():
    raw = request.get_data()                       # raw bytes, not request.json
    if not verify(raw, request.headers.get("Hamsukhan-Signature", "")):
        return jsonify(ok=False), 401

    e = json.loads(raw)
    if e["test"]:
        return jsonify(ok=True, reference="TEST-OK")

    b = e["booking"]
    # save_lead(delivery_id=e["id"], type=b["type"], name=b["customer_name"], phone=b["phone"],
    #           request=b["request"], when=b["preferred_time"], details=b["details"])
    return jsonify(ok=True, reference=f"LEAD-{b['id']}")`,
        },
        {
            id: 'django',
            label: 'Python · Django',
            code: `import hashlib, hmac, json, time
from django.conf import settings
from django.http import JsonResponse
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_POST

@csrf_exempt                     # Hamsukhan can't send your CSRF token; the signature protects this view
@require_POST
def hamsukhan_webhook(request):
    raw = request.body
    parts = dict(p.strip().split("=", 1) for p in request.headers.get("Hamsukhan-Signature", "").split(",") if "=" in p)
    expected = hmac.new(settings.HAMSUKHAN_WEBHOOK_SECRET.encode(), parts.get("t", "").encode() + b"." + raw, hashlib.sha256).hexdigest()
    if not parts.get("t", "").isdigit() or abs(time.time() - int(parts["t"])) > 300 or not hmac.compare_digest(expected, parts.get("v1", "")):
        return JsonResponse({"ok": False}, status=401)

    e = json.loads(raw)
    if e["test"]:
        return JsonResponse({"ok": True, "reference": "TEST-OK"})

    b = e["booking"]
    lead, _ = Lead.objects.get_or_create(delivery_id=e["id"], defaults={
        "kind": b["type"], "name": b["customer_name"], "phone": b["phone"],
        "request": b["request"], "preferred_time": b["preferred_time"], "details": b["details"],
    })
    return JsonResponse({"ok": True, "reference": f"LEAD-{lead.pk}"})

# urls.py: path("hamsukhan/webhook", hamsukhan_webhook)`,
        },
        {
            id: 'csharp',
            label: 'C# · .NET',
            code: `using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

var app = WebApplication.CreateBuilder(args).Build();
var secret = Encoding.UTF8.GetBytes(Environment.GetEnvironmentVariable("HAMSUKHAN_WEBHOOK_SECRET")!);

app.MapPost("/hamsukhan/webhook", async (HttpRequest req) =>
{
    using var reader = new StreamReader(req.Body, Encoding.UTF8);
    var raw = await reader.ReadToEndAsync();

    var parts = req.Headers["Hamsukhan-Signature"].ToString().Split(',')
        .Select(p => p.Trim().Split('=', 2)).Where(p => p.Length == 2).ToDictionary(p => p[0], p => p[1]);
    if (!parts.TryGetValue("t", out var t) || !parts.TryGetValue("v1", out var v1)
        || Math.Abs(DateTimeOffset.UtcNow.ToUnixTimeSeconds() - long.Parse(t)) > 300)
        return Results.Json(new { ok = false }, statusCode: 401);

    var expected = Convert.ToHexString(HMACSHA256.HashData(secret, Encoding.UTF8.GetBytes($"{t}.{raw}"))).ToLowerInvariant();
    if (!CryptographicOperations.FixedTimeEquals(Encoding.UTF8.GetBytes(expected), Encoding.UTF8.GetBytes(v1)))
        return Results.Json(new { ok = false }, statusCode: 401);

    using var doc = JsonDocument.Parse(raw);
    var e = doc.RootElement;
    if (e.GetProperty("test").GetBoolean()) return Results.Json(new { ok = true, reference = "TEST-OK" });

    var b = e.GetProperty("booking");
    // await db.SaveLeadAsync(e.GetProperty("id").GetString(), b.GetProperty("customer_name").GetString(), ...);
    return Results.Json(new { ok = true, reference = $"LEAD-{b.GetProperty("id").GetInt32()}" });
});

app.Run();`,
        },
        {
            id: 'java',
            label: 'Java · Spring Boot',
            code: `import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.*;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;

@RestController
public class HamsukhanWebhook {
    private final byte[] secret = System.getenv("HAMSUKHAN_WEBHOOK_SECRET").getBytes(StandardCharsets.UTF_8);
    private final ObjectMapper json = new ObjectMapper();

    @PostMapping("/hamsukhan/webhook")
    public ResponseEntity<Map<String, Object>> receive(@RequestBody String raw,
            @RequestHeader(value = "Hamsukhan-Signature", defaultValue = "") String header) throws Exception {
        Map<String, String> p = new HashMap<>();
        for (String part : header.split(",")) { String[] kv = part.trim().split("=", 2); if (kv.length == 2) p.put(kv[0], kv[1]); }
        String t = p.get("t"), v1 = p.get("v1");
        if (t == null || v1 == null || Math.abs(System.currentTimeMillis() / 1000 - Long.parseLong(t)) > 300)
            return ResponseEntity.status(401).body(Map.of("ok", false));

        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(secret, "HmacSHA256"));
        String expected = HexFormat.of().formatHex(mac.doFinal((t + "." + raw).getBytes(StandardCharsets.UTF_8)));
        if (!MessageDigest.isEqual(expected.getBytes(), v1.getBytes()))
            return ResponseEntity.status(401).body(Map.of("ok", false));

        JsonNode e = json.readTree(raw);
        if (e.get("test").asBoolean()) return ResponseEntity.ok(Map.of("ok", true, "reference", "TEST-OK"));

        JsonNode b = e.get("booking");
        // leadRepository.saveIfNew(e.get("id").asText(), b.get("customer_name").asText(), b.get("phone").asText(), ...);
        return ResponseEntity.ok(Map.of("ok", true, "reference", "LEAD-" + b.get("id").asInt()));
    }
}`,
        },
        {
            id: 'go',
            label: 'Go',
            code: `package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"math"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"
)

var secret = []byte(os.Getenv("HAMSUKHAN_WEBHOOK_SECRET"))

func verify(raw []byte, header string) bool {
	p := map[string]string{}
	for _, part := range strings.Split(header, ",") {
		if kv := strings.SplitN(strings.TrimSpace(part), "=", 2); len(kv) == 2 {
			p[kv[0]] = kv[1]
		}
	}
	t, err := strconv.ParseInt(p["t"], 10, 64)
	if err != nil || math.Abs(float64(time.Now().Unix()-t)) > 300 {
		return false
	}
	mac := hmac.New(sha256.New, secret)
	mac.Write([]byte(p["t"] + "." + string(raw)))
	return hmac.Equal([]byte(hex.EncodeToString(mac.Sum(nil))), []byte(p["v1"]))
}

func webhook(w http.ResponseWriter, r *http.Request) {
	raw, _ := io.ReadAll(r.Body)
	w.Header().Set("Content-Type", "application/json")
	if !verify(raw, r.Header.Get("Hamsukhan-Signature")) {
		w.WriteHeader(http.StatusUnauthorized)
		w.Write([]byte(\`{"ok":false}\`))
		return
	}
	var e struct {
		ID      string \`json:"id"\`
		Test    bool   \`json:"test"\`
		Booking struct {
			ID           int               \`json:"id"\`
			Type         string            \`json:"type"\`
			CustomerName string            \`json:"customer_name"\`
			Phone        string            \`json:"phone"\`
			Request      string            \`json:"request"\`
			Details      map[string]any    \`json:"details"\`
		} \`json:"booking"\`
	}
	json.Unmarshal(raw, &e)
	if e.Test {
		w.Write([]byte(\`{"ok":true,"reference":"TEST-OK"}\`))
		return
	}
	// saveLead(e.ID, e.Booking) — skip if e.ID was already saved
	fmt.Fprintf(w, \`{"ok":true,"reference":"LEAD-%d"}\`, e.Booking.ID)
}

func main() {
	http.HandleFunc("/hamsukhan/webhook", webhook)
	http.ListenAndServe(":8080", nil)
}`,
        },
        {
            id: 'curl',
            label: 'Try with cURL',
            note: <>Send yourself a correctly signed test event from a terminal (macOS / Linux / Git Bash), to check your endpoint before going live.</>,
            code: `SECRET='whsec_...'
URL='https://your-app.com/hamsukhan/webhook'
BODY='{"event":"booking.created","id":"local-test-1","test":true,"created_at":"2026-11-03T10:00:00+00:00","bot":{"id":1,"business":"My shop"},"booking":{"id":1,"type":"booking","status":"new","customer_name":"Test","phone":"+923001234567","request":"Haircut","preferred_time":"Tomorrow 5 PM","details":{}},"call":null}'
T=$(date +%s)
SIG=$(printf '%s' "$T.$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

curl -i -X POST "$URL" \\
  -H 'Content-Type: application/json' \\
  -H "Hamsukhan-Signature: t=$T,v1=$SIG" \\
  -H 'Hamsukhan-Event: booking.created' \\
  -H 'Hamsukhan-Delivery: local-test-1' \\
  --data "$BODY"

# (your production URL for reference: ${url})`,
        },
    ];
}

export default function Docs({ appUrl, site, supportEmail }: Props) {
    const [active, setActive] = useState('start');

    // Highlight the section being read in the side menu.
    useEffect(() => {
        const io = new IntersectionObserver((entries) => entries.forEach((e) => e.isIntersecting && setActive(e.target.id)), { rootMargin: '-20% 0px -70% 0px' });
        NAV.forEach(([id]) => {
            const el = document.getElementById(id);
            if (el) io.observe(el);
        });
        return () => io.disconnect();
    }, []);

    const widget = `<script src="${appUrl}/widget.js" data-bot="YOUR-BOT-KEY" defer></script>`;

    return (
        <div className="docs">
            <Head title={`Guide & developer docs · ${site}`} />
            <header className="docs-top">
                <Link href="/" aria-label={`${site} home`}>
                    <Logo />
                </Link>
                <span className="docs-top-t">Guide & docs</span>
                <div className="grow" />
                <Link href="/login" className="btn btn-ghost btn-sm">
                    Log in
                </Link>
                <Link href="/register" className="btn btn-primary btn-sm">
                    Get started
                </Link>
            </header>

            <div className="docs-body">
                <nav className="docs-nav" aria-label="On this page">
                    {NAV.map(([id, label]) => (
                        <a key={id} href={`#${id}`} className={active === id ? 'on' : ''}>
                            {label}
                        </a>
                    ))}
                </nav>

                <main className="docs-main">
                    <div className="docs-hero">
                        <span className="lp-kicker">Guide & developer docs</span>
                        <h1>Everything you need to set up {site} and get every booking and order into your own system.</h1>
                        <p>Part 1 is for business owners (no code). Part 2 is for your developer: how bookings and orders reach your software, with copy-paste code for every common language.</p>
                    </div>

                    {/* ---------- Part 1 ---------- */}
                    <section id="start">
                        <h2>Quick start (about 10 minutes)</h2>
                        <Steps
                            items={[
                                <><b>Create your account</b> at <Link href="/register">Get started</Link>. Your first assistant is created straight away.</>,
                                <><b>Choose a plan</b> in <b>Billing</b>: Basic (1 store, 1 user) or Business (5 stores, 5 users). Calls are unlimited (fair use). Pay online or by JazzCash / Easypaisa / bank.</>,
                                <><b>Teach it your business</b> in <b>Bot Studio → Instructions</b>: what you sell, prices, timings, address and rules. Add <b>Quick facts</b> or upload your menu / price list. The bot only says what is written there.</>,
                                <><b>Pick a voice</b>: a ready Urdu/English voice, or record 1–2 minutes to clone your own (with consent). Choose the language: English, Urdu, or Urdu + English. It speaks Urdu, never Hindi.</>,
                                <><b>Choose what it collects</b> (next section): e.g. order items and address for a restaurant, date and service for a salon.</>,
                                <><b>Put it on your website</b> (one line of code, or one click on Shopify) and press <b>Test your bot</b> at the top of the dashboard.</>,
                            ]}
                        />
                    </section>

                    <section id="install">
                        <h2>Put it on your website</h2>
                        <p>Copy your own line from <b>Bot Studio → Add to your website</b> (it already has your bot key). It looks like this:</p>
                        <Code code={widget} label="HTML" />
                        <div className="docs-grid">
                            <div>
                                <h4>Any HTML website</h4>
                                <p>Paste the line just before <code>&lt;/body&gt;</code> on every page (or in your shared footer).</p>
                            </div>
                            <div>
                                <h4>WordPress</h4>
                                <p>Appearance → Theme File Editor → <code>footer.php</code>, paste before <code>&lt;/body&gt;</code>. Or use any "header & footer scripts" plugin.</p>
                            </div>
                            <div>
                                <h4>Shopify</h4>
                                <p>Integrations → <b>Shopify</b> → enter your store name → Connect. The call button is added for you. Without the app: Online Store → Themes → Edit code → <code>theme.liquid</code>, paste before <code>&lt;/body&gt;</code>.</p>
                            </div>
                            <div>
                                <h4>Wix / Squarespace / Webflow</h4>
                                <p>Settings → Custom code (Wix) / Code injection (Squarespace) / Footer code (Webflow), "body end", all pages.</p>
                            </div>
                            <div>
                                <h4>React / Next.js</h4>
                                <p>Load it once, e.g. Next.js: <code>&lt;Script src="{appUrl}/widget.js" data-bot="…" strategy="afterInteractive" /&gt;</code> in your root layout.</p>
                            </div>
                            <div>
                                <h4>Allowed websites</h4>
                                <p>Bot Studio → Allowed websites: list your domains so nobody can copy your code. On Shopify add both <code>your-store.myshopify.com</code> and your own domain.</p>
                            </div>
                        </div>
                    </section>

                    <section id="collect">
                        <h2>What the bot collects</h2>
                        <p>
                            Every booking, order or callback always has <b>customer name</b>, <b>phone / WhatsApp</b>, <b>request</b> and <b>preferred time</b>. In <b>Bot Studio → What should the bot collect?</b> you add up to 12 extra fields; the bot asks for the ones marked "must ask".
                        </p>
                        <div className="docs-grid three">
                            <div>
                                <h4>Restaurant order</h4>
                                <p>
                                    <code>order_type</code> (delivery / takeaway), <code>items</code>, <code>address</code>, <code>notes</code>
                                </p>
                            </div>
                            <div>
                                <h4>Clinic appointment</h4>
                                <p>
                                    <code>doctor</code>, <code>date</code>, <code>time</code>, <code>reason</code>
                                </p>
                            </div>
                            <div>
                                <h4>Salon booking</h4>
                                <p>
                                    <code>service</code>, <code>date</code>, <code>time</code>, <code>stylist</code>
                                </p>
                            </div>
                        </div>
                        <Tip>The field <b>key</b> (e.g. <code>items</code>) is the name your developer will see in <code>booking.details</code>. Keep keys stable once your software uses them.</Tip>
                    </section>

                    <section id="bookings">
                        <h2>How bookings & orders work</h2>
                        <Steps
                            items={[
                                <><b>During the call</b> the assistant answers questions and collects the fields above. With <b>Bot Studio → Opening hours</b> set and live booking switched on there, it checks free slots (and your Google Calendar) and books the time on the call.</>,
                                <><b>When the call ends</b> a booking appears in <b>Bookings</b> as <code>booking</code>, <code>order</code> or <code>callback</code>, with the recording, transcript and summary.</>,
                                <><b>You are alerted</b> by email and/or WhatsApp (Settings → Alerts) within seconds.</>,
                                <><b>It is sent to your software</b> automatically if you added a connector (next section), and the reference your system returns is shown next to the booking.</>,
                                <><b>You follow up</b>: mark it <i>confirmed</i> / <i>done</i>, press <b>AI call back</b> to let the assistant phone the customer, or let it make reminder calls before appointments.</>,
                            ]}
                        />
                        <Tip>Visitors who don't want to talk can leave their number with the <b>Request a callback</b> form in the widget; it arrives as a <code>callback</code>.</Tip>
                    </section>

                    {/* ---------- Part 2 ---------- */}
                    <section id="integrations">
                        <h2>Send bookings & orders to your software</h2>
                        <p>
                            Go to <b>Integrations → Add connector</b>. Each store can have up to 5 connectors. Choose which events to send (<code>booking.created</code>, <code>order.created</code>, <code>callback.created</code>) and one of two kinds:
                        </p>
                        <div className="docs-grid">
                            <div>
                                <h4>Webhook (JSON)</h4>
                                <p>We POST the full event as JSON to your URL, signed with your connector secret. Best when you (or your developer) can add one endpoint. Code for every language is below.</p>
                            </div>
                            <div>
                                <h4>Form fields</h4>
                                <p>
                                    We POST like a normal web form (<code>application/x-www-form-urlencoded</code>) to an existing form's URL. Map <b>Your form field name</b> ← <b>Gets this value</b> (or a fixed text). No code needed if your system already has a form that accepts a plain POST.
                                </p>
                            </div>
                        </div>
                        <Steps
                            items={[
                                <>Integrations → <b>Add connector</b> → name it, pick the kind, paste the URL (must be <code>https://</code>).</>,
                                <>Tick the events under <b>Send when</b>. For Form fields, fill the <b>Field mapping</b>.</>,
                                <>Save, then press <b>Send test</b>. The <b>Delivery log</b> shows the status code, your answer and <b>Your reference</b>.</>,
                                <>Give the connector's <b>Signing secret</b> (copy button on the connector) to whoever builds the endpoint, so it can check the signature.</>,
                            ]}
                        />
                    </section>

                    <section id="nocode">
                        <h2>No-code options</h2>
                        <div className="docs-grid">
                            <div>
                                <h4>Google Sheets, CRM, email, Slack… via Zapier or Make</h4>
                                <p>
                                    Create a Zap with <b>Webhooks by Zapier → Catch Hook</b> (or a Make <b>Custom webhook</b>), copy its URL into a <b>Webhook (JSON)</b> connector, press <b>Send test</b>, then add an action such as <i>Google Sheets → Create row</i> using <code>booking.customer_name</code>, <code>booking.phone</code>, <code>booking.details.items</code> and so on.
                                </p>
                            </div>
                            <div>
                                <h4>An existing website form</h4>
                                <p>Use a <b>Form fields</b> connector with the form's action URL and its field names (right-click the form → Inspect to see <code>name="…"</code>). Forms protected by captcha or a hidden security token can't be filled this way; use a webhook instead.</p>
                            </div>
                            <div>
                                <h4>Just alerts</h4>
                                <p>No system at all? Turn on email / WhatsApp alerts in Settings and work from the <b>Bookings</b> page; export calls as CSV from Call Logs.</p>
                            </div>
                        </div>
                    </section>

                    <section id="webhook">
                        <h2>Webhook reference</h2>
                        <h3>Request</h3>
                        <table className="docs-table">
                            <tbody>
                                <tr>
                                    <td>Method</td>
                                    <td>
                                        <code>POST</code>, body <code>application/json</code> (Form fields connector: <code>application/x-www-form-urlencoded</code>)
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <code>Hamsukhan-Signature</code>
                                    </td>
                                    <td>
                                        <code>t=&lt;unix time&gt;,v1=&lt;hex HMAC-SHA256&gt;</code> (see Signature check)
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <code>Hamsukhan-Event</code>
                                    </td>
                                    <td>
                                        <code>booking.created</code> · <code>order.created</code> · <code>callback.created</code>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <code>Hamsukhan-Delivery</code>
                                    </td>
                                    <td>Unique id of this delivery (same as <code>id</code> in the body). Retries reuse it: save it and skip repeats.</td>
                                </tr>
                                <tr>
                                    <td>
                                        <code>User-Agent</code>
                                    </td>
                                    <td>
                                        <code>Hamsukhan-Connector/1.0</code>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <Code code={PAYLOAD} label="JSON body" />
                        <table className="docs-table">
                            <tbody>
                                <tr>
                                    <td>
                                        <code>test</code>
                                    </td>
                                    <td>
                                        <code>true</code> for the <b>Send test</b> button: answer 2xx but don't save it as a real order.
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <code>booking.type</code>
                                    </td>
                                    <td>
                                        <code>booking</code> · <code>order</code> · <code>callback</code>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <code>booking.phone</code>
                                    </td>
                                    <td>
                                        Usually in <code>+92…</code> format (we normalise Pakistani numbers); can be <code>null</code> if the caller gave none.
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <code>booking.details</code>
                                    </td>
                                    <td>Your custom fields by key (empty object if none). Lists arrive as "a, b, c".</td>
                                </tr>
                                <tr>
                                    <td>
                                        <code>call</code>
                                    </td>
                                    <td>
                                        The call it came from, or <code>null</code> for the website callback form.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <h3>Your answer</h3>
                        <p>
                            Reply within <b>10 seconds</b> with any <b>2xx</b>. Optionally return JSON <code>{'{"ok": true, "reference": "ORD-1042"}'}</code> (or <code>"id"</code>): it is shown next to the booking in {site}.
                        </p>
                        <table className="docs-table">
                            <tbody>
                                <tr>
                                    <td>2xx</td>
                                    <td>Delivered.</td>
                                </tr>
                                <tr>
                                    <td>4xx (not 408 / 429)</td>
                                    <td>Failed for good, not retried (e.g. bad signature, wrong URL). The owner is alerted.</td>
                                </tr>
                                <tr>
                                    <td>5xx, 408, 429, timeout, redirect</td>
                                    <td>
                                        Retried after <b>1 min, 5 min, 30 min and 2 hours</b>, then marked failed. Owners can press <b>Retry now</b>.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <section id="examples">
                        <h2>Code by language</h2>
                        <p>Each example checks the signature, ignores test events, saves the booking and answers with a reference. Replace the save line with your own database code.</p>
                        <Tabs group="lang" items={examples(appUrl)} />
                    </section>

                    <section id="security">
                        <h2>Signature check</h2>
                        <Steps
                            items={[
                                <>Read the <b>raw request body</b> exactly as received (before JSON parsing or form decoding).</>,
                                <>
                                    Split the <code>Hamsukhan-Signature</code> header on commas into <code>t</code> and <code>v1</code>.
                                </>,
                                <>
                                    Compute <code>HMAC-SHA256(secret, t + "." + rawBody)</code> as lowercase hex, and compare it with <code>v1</code> using a constant-time compare.
                                </>,
                                <>
                                    Reject if <code>t</code> is more than <b>5 minutes</b> away from your server's time (stops replayed requests). Keep your server clock synced.
                                </>,
                            ]}
                        />
                        <Tip kind="warn">
                            The #1 mistake: computing the HMAC over re-encoded JSON (e.g. <code>JSON.stringify(req.body)</code>). Always use the raw bytes. The secret starts with <code>whsec_</code>; keep it on your server only. If it leaks, press the key button on the connector in Integrations to create a new one.
                        </Tip>
                    </section>

                    <section id="testing">
                        <h2>Test & troubleshoot</h2>
                        <div className="docs-grid">
                            <div>
                                <h4>Test the whole flow</h4>
                                <p>Press <b>Send test</b> on the connector, then make a real <b>Test your bot</b> call and place an order. Check the <b>Delivery log</b> for the status and your reference.</p>
                            </div>
                            <div>
                                <h4>401 / bad signature</h4>
                                <p>Wrong secret, signature computed on parsed JSON instead of the raw body, or your server clock is off by more than 5 minutes.</p>
                            </div>
                            <div>
                                <h4>Timeouts / retries</h4>
                                <p>Answer fast (under 10 s): save the booking and return 2xx, do slow work (emails, invoices) afterwards. Use the delivery id to skip duplicates.</p>
                            </div>
                            <div>
                                <h4>Call button doesn't show</h4>
                                <p>Check the line is before <code>&lt;/body&gt;</code>, your domain is in Allowed websites, and the plan is active (Billing).</p>
                            </div>
                            <div>
                                <h4>"All lines are busy"</h4>
                                <p>Your plan's calls-at-the-same-time limit is reached. Visitors get the callback form, so no lead is lost.</p>
                            </div>
                            <div>
                                <h4>Local testing</h4>
                                <p>Webhook URLs must be public <code>https</code>. To test on your laptop, expose it with a tunnel (e.g. ngrok or Cloudflare Tunnel) and use that URL.</p>
                            </div>
                        </div>
                    </section>

                    <section id="faq">
                        <h2>FAQ</h2>
                        {[
                            ['Can one booking go to two systems?', 'Yes. Add two connectors (up to 5 per store); each gets its own delivery and retries.'],
                            ['Do I get updates when a booking is confirmed or cancelled?', 'Connectors send the moment a booking, order or callback is created. Status changes stay in your dashboard.'],
                            ['I have several stores. Are connectors shared?', 'No, each store has its own connectors and secret, so each branch can send to its own system. The bot id and business name are in every event.'],
                            ['Is my data safe?', 'Deliveries go over HTTPS, are signed with your secret, and private/local network addresses are refused. Secrets are stored encrypted.'],
                            ['Which languages does the assistant speak?', 'English, Urdu, or both mixed, never Hindi. Urdu replies use Urdu words (shukriya, meherbani, waqt…).'],
                        ].map(([q, a]) => (
                            <details key={q} className="docs-faq">
                                <summary>{q}</summary>
                                <p>{a}</p>
                            </details>
                        ))}
                        <p style={{ marginTop: 18 }}>
                            Still stuck? {supportEmail ? <a href={`mailto:${supportEmail}`}>{supportEmail}</a> : <Link href="/#contact">Contact us</Link>}. We are happy to help your developer.
                        </p>
                    </section>
                </main>
            </div>
        </div>
    );
}
