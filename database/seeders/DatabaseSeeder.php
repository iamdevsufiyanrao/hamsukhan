<?php

namespace Database\Seeders;

use App\Models\Bot;
use App\Models\Call;
use App\Models\TopUp;
use App\Models\User;
use App\Support\CallScenarios;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Demo data that looks like a real account:
 *  - admin@hamsukhan.test / password            (admin: approves manual top-ups)
 *  - a@smartgeeks.io    / password            (Smart Geeks, ~60 days of calls)
 *  - sana@bloomsalon.pk / password            (Bloom Salon, newer account)
 * Deterministic (fixed random seed), so every `migrate:fresh --seed` gives the same numbers.
 */
class DatabaseSeeder extends Seeder
{
    private float $rate;

    public function run(): void
    {
        mt_srand(42);
        $this->rate = config('hamsukhan.rate_per_minute');

        DB::transaction(function () {
            $admin = User::create(['name' => 'Hamsukhan Admin', 'email' => 'admin@hamsukhan.test', 'password' => 'password', 'terms_accepted_at' => now()]);
            $admin->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

            $aemal = $this->smartGeeks();
            $sana = $this->bloomSalon();
            $this->karachiGrill();

            // Reviewed history + one pending payment waiting for the admin.
            $this->pendingTopUp($aemal, 10, 'jazzcash', 'JC-88213409', 0);
            $this->pendingTopUp($sana, 20, 'easypaisa', 'EP-55102377', 2);
            $sana->topUps()->create([
                'amount_usd' => 15, 'amount_pkr' => 15 * config('hamsukhan.pkr_per_usd'), 'method' => 'bank', 'reference' => 'FT-0099',
                'status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now()->subDays(3), 'admin_note' => 'Amount not received in bank account',
                'created_at' => now()->subDays(4),
            ]);
        });
    }

    private function smartGeeks(): User
    {
        $user = $this->customer('Aemal Sayer', 'a@smartgeeks.io', 70);

        $voice = $user->voices()->create(['name' => 'Aemal Sayer', 'provider_voice_id' => 'sim_aemal_main', 'sample_seconds' => 42, 'consent_at' => now()->subDays(65), 'created_at' => now()->subDays(65)]);
        $user->voices()->create(['name' => 'Aemal, calm', 'provider_voice_id' => 'sim_aemal_calm', 'sample_seconds' => 51, 'consent_at' => now()->subDays(40), 'created_at' => now()->subDays(40)]);

        $widget = Bot::defaultWidget();
        $widget['states']['idle']['title'] = 'Need a repair?';
        $widget['states']['idle']['sub'] = 'Talk to Smart Geeks now';

        $bot = $user->bot()->create([
            'business_name' => 'Smart Geeks',
            'website' => 'smartgeeks.io',
            'greeting' => "Hi, thanks for calling Smart Geeks! I'm Aemal's assistant, how can I help you today?",
            'end_message' => 'Thanks for calling Smart Geeks, have a great day!',
            'language' => 'English',
            'prompt' => "You are the friendly receptionist for Smart Geeks, a computer and phone repair shop in Lahore.\n\n- Greet callers warmly and ask how you can help.\n- Take repair bookings: collect name, phone number, device and the problem.\n- Opening hours: Mon–Sat, 10am–8pm. Closed Sunday.\n- For prices, give the range from the price list and say a technician will confirm.\n- Keep answers short and natural. Never make up information.",
            'quick_facts' => "Address: 12 Main Boulevard, Gulberg, Lahore (next to the bank).\nFree parking behind the shop. Free diagnosis on all devices.\nPayment: cash, card, JazzCash.",
            'capture_fields' => [
                ['key' => 'device', 'label' => 'Device', 'type' => 'text', 'required' => true, 'hint' => 'make and model'],
                ['key' => 'issue', 'label' => 'Problem', 'type' => 'text', 'required' => true, 'hint' => null],
            ],
            'max_call_minutes' => 10,
            'voice_id' => $voice->id,
            'widget' => $widget,
            'vapi_assistant_id' => 'sim_asst_smartgeeks',
            'synced_at' => now()->subHours(2),
        ]);
        $bot->forceFill(['created_at' => now()->subDays(70), 'updated_at' => now()->subHours(2)])->save();

        $this->knowledge($bot, [
            'Price list 2026.csv' => "Service,Min (PKR),Max (PKR)\nLaptop screen replacement,18000,45000\niPhone battery replacement,9000,14000\nSSD upgrade 512GB incl. install,16500,16500\nData recovery (HDD),8000,25000\nKeyboard replacement,7000,15000\nGeneral service & cleaning,2500,4000\n",
            'Repair FAQ.md' => "# Smart Geeks FAQ\n\n**How long does a repair take?** Most repairs are same day. Screens take 1–2 days.\n\n**Do you give a warranty?** 90 days on parts and labour.\n\n**Can I walk in?** Yes, Mon–Sat 10am–8pm.\n",
        ]);

        $this->history($user, $bot, days: 60, perDay: [3, 9], todayCalls: 13, topUps: [[60, 50, 'bank', 'FT-20431'], [34, 25, 'jazzcash', 'JC-77310021'], [11, 25, 'easypaisa', 'EP-44019875']]);

        // Their own repair-shop software receives every booking/order through the Laravel connector.
        $crm = $bot->integrations()->create(['name' => 'Shop CRM (Laravel)', 'kind' => 'webhook', 'url' => 'https://crm.smartgeeks.io/hamsukhan/webhook', 'events' => ['booking.created', 'order.created'], 'secret' => 'whsec_demo_smartgeeks_crm_0000000000000000']);
        $this->deliveryHistory($crm, $bot->bookings()->where('created_at', '>=', now()->subDays(14))->get(), 'JOB-');

        return $user;
    }

    private function karachiGrill(): void
    {
        $user = $this->customer('Ali Raza', 'ali@karachigrill.pk', 22);
        $user->forceFill(['timezone' => 'Asia/Karachi'])->save();
        $bot = $user->bot()->create([
            'business_name' => 'Karachi Grill',
            'website' => 'karachigrill.pk',
            'greeting' => 'Assalam o alaikum, Karachi Grill! Would you like to place an order?',
            'end_message' => 'Shukriya! Enjoy your meal.',
            'language' => 'English',
            'prompt' => "You take phone orders and table reservations for Karachi Grill, a BBQ restaurant in Karachi.\n\n- Ask if the order is for delivery, pickup or dine-in.\n- Take each item with quantity. Only offer items from the menu file.\n- Read the full order back and confirm the total.\n- For delivery, collect name, phone number and full address.\n- Delivery takes 35–40 minutes. Free delivery above Rs 2,000.",
            'quick_facts' => "Open daily 12pm–1am.\nDelivery: all of DHA, Clifton, Gulshan, PECHS.\nPayment: cash on delivery, JazzCash, card.",
            'capture_fields' => \App\Support\CaptureFields::presets()['restaurant'],
            'preset_voice' => 'preset_ahmed',
            'vapi_assistant_id' => 'sim_asst_karachigrill',
            'synced_at' => now()->subDays(1),
        ]);
        $this->knowledge($bot, ['Menu.csv' => "Item,Price (PKR)\nGrill platter (large),3200\nMixed BBQ platter,2800\nSeekh kebab platter,1900\nChicken tikka platter,1700\nHalf chicken karahi,1600\nGarlic naan,120\nRoti,40\nMint margarita,350\nFresh lime,250\nLassi,300\n"]);
        $this->history($user, $bot, days: 21, perDay: [4, 10], todayCalls: 6, topUps: [[22, 25, 'jazzcash', 'JC-50311002']], set: 'restaurant');

        // Their POS has a plain HTML order form, the form connector fills it field by field.
        $pos = $bot->integrations()->create([
            'name' => 'POS order form', 'kind' => 'form', 'url' => 'https://pos.karachigrill.pk/orders/new', 'events' => ['order.created'],
            'secret' => 'whsec_demo_karachigrill_pos_000000000000000',
            'field_map' => [
                ['target' => 'customer', 'source' => 'customer_name'],
                ['target' => 'mobile', 'source' => 'phone'],
                ['target' => 'order_items', 'source' => 'details.items'],
                ['target' => 'order_type', 'source' => 'details.order_type'],
                ['target' => 'delivery_address', 'source' => 'details.address'],
                ['target' => 'channel', 'source' => '', 'value' => 'phone-bot'],
            ],
        ]);
        $this->deliveryHistory($pos, $bot->bookings()->where('type', 'order')->where('created_at', '>=', now()->subDays(10))->get(), 'POS-');
    }

    /** Past deliveries to a connector: mostly delivered, one still retrying. */
    private function deliveryHistory(\App\Models\Integration $integration, $bookings, string $refPrefix): void
    {
        $payloads = app(\App\Services\Integrations\PayloadBuilder::class);
        $last = null;
        foreach ($bookings->sortBy('created_at')->values() as $i => $booking) {
            if (! $integration->listensTo(\App\Services\Integrations\PayloadBuilder::eventFor($booking))) {
                continue;
            }
            $failing = $i === $bookings->count() - 2;
            $d = $integration->deliveries()->make(['event' => \App\Services\Integrations\PayloadBuilder::eventFor($booking), 'booking_id' => $booking->id, 'payload' => []]);
            $d->uuid = (string) \Illuminate\Support\Str::uuid();
            $d->payload = $payloads->event($booking, $d->uuid);
            $d->fill($failing
                ? ['status' => 'pending', 'attempts' => 2, 'response_code' => 503, 'response_body' => '<html>503 Service Unavailable</html>', 'next_attempt_at' => now()->addMinutes(4)]
                : ['status' => 'delivered', 'attempts' => 1, 'response_code' => 200, 'response_body' => '{"ok":true}', 'external_reference' => $refPrefix.(1000 + $booking->id), 'delivered_at' => $booking->created_at->copy()->addSeconds(2)]);
            $d->save();
            $d->forceFill(['created_at' => $booking->created_at, 'updated_at' => $booking->created_at])->save();
            $last = $d;
        }
        if ($last) {
            $integration->forceFill(['last_delivery_at' => $last->created_at, 'last_status' => $last->status === 'pending' ? 'retrying' : $last->status])->save();
        }
    }

    private function bloomSalon(): User
    {
        $user = $this->customer('Sana Khan', 'sana@bloomsalon.pk', 9);
        $bot = $user->bot()->create([
            'business_name' => 'Bloom Salon',
            'greeting' => 'Hi, welcome to Bloom Salon! Would you like to book an appointment?',
            'end_message' => 'Thank you, see you soon!',
            'language' => 'Urdu',
            'prompt' => "You book appointments for Bloom Salon.\n\n- Ask which service they want and offer prices from the price list.\n- Collect name, phone number and preferred day and time.\n- Mention the cancellation policy: 24 hours notice.",
            'preset_voice' => 'preset_sara',
            'vapi_assistant_id' => 'sim_asst_bloom',
            'synced_at' => now()->subDays(1),
        ]);
        $this->history($user, $bot, days: 8, perDay: [0, 3], todayCalls: 2, topUps: [[9, 10, 'easypaisa', 'EP-10022']]);

        return $user;
    }

    private function customer(string $name, string $email, int $ageDays): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'terms_accepted_at' => now()->subDays($ageDays)]);
        $user->forceFill(['email_verified_at' => now()->subDays($ageDays), 'created_at' => now()->subDays($ageDays)])->save();

        return $user;
    }

    private function knowledge(Bot $bot, array $files): void
    {
        foreach ($files as $name => $content) {
            $path = "knowledge/{$bot->id}/".str($name)->slug().'.'.pathinfo($name, PATHINFO_EXTENSION);
            Storage::put($path, $content);
            $bot->knowledgeFiles()->create(['name' => $name, 'path' => $path, 'size_bytes' => strlen($content), 'mime' => 'text/plain', 'status' => 'indexed']);
        }
    }

    /**
     * Calls during business hours (10:00–20:00 PKT, closed Sunday), with a wallet ledger that
     * replays top-ups and per-call charges in time order, exactly like WalletService would.
     */
    private function history(User $user, Bot $bot, int $days, array $perDay, int $todayCalls, array $topUps, string $set = 'repair'): void
    {
        $tz = $user->timezone;
        $scenarios = CallScenarios::all($set);
        // How common each scenario is (repair: ~1 in 3 calls ends in a booking/order; restaurant: most calls are orders).
        $weights = $set === 'restaurant' ? [6, 4, 2, 2, 1] : [4, 4, 4, 2, 3, 2, 2, 3];
        $events = [];

        foreach ($topUps as [$ago, $usd, $method, $ref]) {
            $events[] = ['at' => now($tz)->subDays($ago)->setTime(10, 15), 'topup' => [$usd, $method, $ref]];
        }

        $nowLocal = now($tz);
        for ($ago = $days; $ago >= 0; $ago--) {
            $day = $nowLocal->copy()->subDays($ago)->startOfDay();
            if ($day->isSunday() && $ago !== 0) {
                continue;
            }
            $count = $ago === 0 ? $todayCalls : mt_rand($perDay[0], $perDay[1]) + ($day->isSaturday() ? 2 : 0);
            for ($i = 0; $i < $count; $i++) {
                $hour = $this->busyHour();
                $at = $day->copy()->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
                if ($ago === 0 && $at->greaterThan($nowLocal)) {
                    $at = $nowLocal->copy()->subMinutes(mt_rand(5, max(6, $nowLocal->hour * 60 - 5)));
                }
                $events[] = ['at' => $at, 'call' => $this->pick($weights)];
            }
        }

        usort($events, fn ($a, $b) => $a['at'] <=> $b['at']);

        $balance = 0.0;
        $n = 0;
        foreach ($events as $e) {
            $at = $e['at']->copy()->utc();

            if (isset($e['topup'])) {
                [$usd, $method, $ref] = $e['topup'];
                $balance = $this->credit($user, $usd, $method, $ref, $at, $balance);

                continue;
            }

            $n++;
            $failed = mt_rand(1, 100) <= 3;
            $sc = CallScenarios::render($scenarios[$e['call']], $n + $bot->id * 1000);
            $seconds = $failed ? 0 : ($sc['status'] === 'dropped' ? mt_rand(5, 15) : mt_rand(45, 300));
            $cost = round($seconds * $this->rate / 60, 4);

            // Keep the demo account funded the way a real owner would: top up before running dry.
            if ($balance - $cost < 1) {
                $balance = $this->credit($user, 25, 'jazzcash', 'JC-'.mt_rand(10000000, 99999999), $at->copy()->subHour(), $balance);
            }

            $call = $bot->calls()->create([
                'vapi_call_id' => 'seed_'.$bot->id.'_'.$n,
                'title' => $failed ? 'Call failed to connect' : $sc['title'],
                'outcome_tag' => $failed ? 'No outcome' : $sc['outcome'],
                'status' => $failed ? 'failed' : $sc['status'],
                'ended_reason' => $failed ? 'pipeline-error-microphone-blocked' : ($sc['status'] === 'dropped' ? 'customer-ended-call' : 'assistant-ended-call'),
                'is_test' => $n % 40 === 0,
                'started_at' => $at,
                'ended_at' => $at->copy()->addSeconds($seconds),
                'duration_seconds' => $seconds,
                'cost' => $cost,
                'summary' => $failed ? 'Call failed to connect: microphone permission was blocked in the browser.' : $sc['summary'],
            ]);
            $call->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            if (! $failed) {
                $call->messages()->createMany(array_map(fn ($t, $i) => ['role' => $t[0], 'text' => $t[1], 'position' => $i], $sc['turns'], array_keys($sc['turns'])));
            }

            if (! $failed && $sc['booking']) {
                $age = now()->diffInDays($at, true);
                $status = $age > 7 ? 'done' : ($age > 2 ? (mt_rand(0, 1) ? 'confirmed' : 'done') : (mt_rand(0, 3) ? 'new' : 'confirmed'));
                $booking = $bot->bookings()->create([
                    'call_id' => $call->id, 'type' => $sc['booking']['type'], 'customer_name' => $sc['booking']['customerName'], 'phone' => $sc['booking']['phone'],
                    'request' => $sc['booking']['request'], 'preferred_time' => $sc['booking']['preferredTime'], 'status' => $status,
                    'details' => \App\Support\CaptureFields::extract($bot->capture_fields ?? [], $sc['booking']['details']) ?: null,
                ]);
                $booking->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
            }

            if ($cost > 0) {
                $balance = round($balance - $cost, 4);
                $user->transactions()->create(['type' => 'call_charge', 'amount' => -$cost, 'balance_after' => $balance, 'description' => 'Call · '.$call->title, 'call_id' => $call->id, 'created_at' => $at->copy()->addSeconds($seconds)]);
            }
        }

        $user->forceFill(['balance' => $balance])->save();
    }

    private function credit(User $user, float $usd, string $method, string $ref, Carbon $at, float $balance): float
    {
        $topUp = $user->topUps()->create([
            'amount_usd' => $usd, 'amount_pkr' => $usd * config('hamsukhan.pkr_per_usd'), 'method' => $method, 'reference' => $ref,
            'status' => 'approved', 'reviewed_by' => User::where('is_admin', true)->value('id'), 'reviewed_at' => $at,
        ]);
        $topUp->forceFill(['created_at' => $at->copy()->subHours(2), 'updated_at' => $at])->save();

        $admin = User::where('is_admin', true)->first();
        \App\Models\AdminAction::create(['admin_id' => $admin->id, 'target_user_id' => $user->id, 'action' => 'approve_topup', 'meta' => ['topUpId' => $topUp->id, 'amount' => $usd, 'reference' => $ref]])
            ->forceFill(['created_at' => $at])->save();

        $balance = round($balance + $usd, 4);
        $user->transactions()->create(['type' => 'topup', 'amount' => $usd, 'balance_after' => $balance, 'description' => "Top-up · {$topUp->methodLabel()} · {$ref}", 'top_up_id' => $topUp->id, 'created_at' => $at]);

        return $balance;
    }

    private function pendingTopUp(User $user, float $usd, string $method, string $ref, int $hoursAgo): void
    {
        $path = "top-ups/{$user->id}/".strtolower($ref).'.png';
        Storage::put($path, $this->receiptImage($method, $ref, $usd * config('hamsukhan.pkr_per_usd')));

        $topUp = $user->topUps()->create(['amount_usd' => $usd, 'amount_pkr' => $usd * config('hamsukhan.pkr_per_usd'), 'method' => $method, 'reference' => $ref, 'proof_path' => $path]);
        $topUp->forceFill(['created_at' => now()->subHours($hoursAgo)->subMinutes(20)])->save();
    }

    /** A simple payment-receipt screenshot, so the admin review screen has a real image to open. */
    private function receiptImage(string $method, string $ref, float $pkr): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
        }
        $img = imagecreatetruecolor(360, 220);
        $bg = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 26, 23, 20);
        $brand = $method === 'easypaisa' ? imagecolorallocate($img, 34, 139, 84) : imagecolorallocate($img, 200, 30, 45);
        imagefilledrectangle($img, 0, 0, 360, 220, $bg);
        imagefilledrectangle($img, 0, 0, 360, 44, $brand);
        imagestring($img, 5, 14, 14, strtoupper($method).'  -  Payment successful', $bg);
        imagestring($img, 4, 14, 70, 'Amount:  Rs '.number_format($pkr), $ink);
        imagestring($img, 4, 14, 100, 'To:      Hamsukhan', $ink);
        imagestring($img, 4, 14, 130, 'TID:     '.$ref, $ink);
        imagestring($img, 3, 14, 170, now()->format('d M Y, h:i A'), $ink);
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function busyHour(): int
    {
        // Peaks around 14:00–16:00 local time.
        $hours = [10 => 3, 11 => 5, 12 => 6, 13 => 6, 14 => 9, 15 => 9, 16 => 7, 17 => 6, 18 => 5, 19 => 3];
        $r = mt_rand(1, array_sum($hours));
        foreach ($hours as $h => $w) {
            if (($r -= $w) <= 0) {
                return $h;
            }
        }

        return 15;
    }

    private function pick(array $weights): int
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $i => $w) {
            if (($r -= $w) <= 0) {
                return $i;
            }
        }

        return 0;
    }
}
