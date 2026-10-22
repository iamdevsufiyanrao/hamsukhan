<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\OutboundService;
use App\Services\VapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Transfer to a person, AI callbacks, reminder calls, phone-number assignment and call direction. */
class PhoneFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function live(float $balance = 10)
    {
        config(['hamsukhan.vapi.private_key' => 'vk', 'hamsukhan.vapi.public_key' => 'pk', 'hamsukhan.vapi.webhook_secret' => 's', 'hamsukhan.vapi.phone_number_id' => 'pn_platform']);
        $user = $this->customer($balance);
        $user->bot->forceFill(['vapi_assistant_id' => 'asst_1', 'sync_error' => null])->save();

        return $user->fresh();
    }

    public function test_transfer_number_adds_the_transfer_tool(): void
    {
        $user = $this->live();
        Http::fake(['api.vapi.ai/*' => Http::response(['id' => 'asst_1'])]);
        $this->actingAs($user)->patch('/bot-studio/phone', ['transferNumber' => '0300 1234567', 'reminderHours' => 24])->assertSessionHasNoErrors();

        $bot = $user->bot->fresh();
        $this->assertSame([ '+923001234567', 24], [$bot->transfer_number, $bot->reminder_hours]);
        $tools = app(VapiService::class)->assistantPayload($bot)['model']['tools'];
        $this->assertSame('transferCall', $tools[0]['type']);
        $this->assertSame('+923001234567', $tools[0]['destinations'][0]['number']);

        $this->actingAs($user)->patch('/bot-studio/phone', ['transferNumber' => 'abc'])->assertSessionHasErrors('transferNumber');
    }

    public function test_owner_can_send_the_ai_to_call_a_lead_back(): void
    {
        $user = $this->live();
        Http::fake(['api.vapi.ai/call' => Http::response(['id' => 'out_1'])]);
        $booking = $user->bot->bookings()->create(['type' => 'callback', 'customer_name' => 'Ayesha', 'phone' => '+923001234567', 'request' => 'Prices?']);

        $this->actingAs($user)->post("/bookings/{$booking->id}/ai-callback")->assertSessionHas('success');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/call') && $r['phoneNumberId'] === 'pn_platform' && $r['customer']['number'] === '+923001234567'
            && $r['assistantId'] === 'asst_1' && $r['assistantOverrides']['metadata']['botId'] === $user->bot->id);
        $this->assertSame('contacted', $booking->fresh()->status);

        // Right away again: refused (no double dialling)
        $this->actingAs($user)->post("/bookings/{$booking->id}/ai-callback")->assertSessionHas('error');
        // Someone else's lead: 404
        $this->actingAs($this->customer())->post("/bookings/{$booking->id}/ai-callback")->assertNotFound();
    }

    public function test_ai_callback_needs_balance(): void
    {
        $user = $this->live(0);
        $booking = $user->bot->bookings()->create(['customer_name' => 'A', 'phone' => '+923001234567', 'request' => 'x']);
        $this->actingAs($user)->post("/bookings/{$booking->id}/ai-callback")->assertSessionHas('error', fn ($m) => str_contains($m, 'balance'));
    }

    public function test_reminder_calls_go_out_once_inside_the_window(): void
    {
        $user = $this->live();
        $user->bot->update(['reminder_hours' => 2]);
        Http::fake(['api.vapi.ai/call' => Http::response(['id' => 'out_r'])]);
        $soon = $user->bot->bookings()->create(['customer_name' => 'A', 'phone' => '03001234567', 'request' => 'Cut', 'status' => 'confirmed', 'starts_at' => now()->addMinutes(90), 'ends_at' => now()->addMinutes(120)]);
        $later = $user->bot->bookings()->create(['customer_name' => 'B', 'phone' => '03001234568', 'request' => 'Cut', 'status' => 'confirmed', 'starts_at' => now()->addHours(10), 'ends_at' => now()->addHours(11)]);

        $this->assertSame(1, app(OutboundService::class)->sendDueReminders());
        $this->assertSame(0, app(OutboundService::class)->sendDueReminders());
        $this->assertNotNull($soon->fresh()->reminder_called_at);
        $this->assertNull($later->fresh()->reminder_called_at);
        Http::assertSentCount(1);
    }

    public function test_admin_assigns_a_vapi_number_to_a_bot(): void
    {
        $user = $this->live();
        Http::fake([
            'api.vapi.ai/phone-number/pn_1' => Http::response(['id' => 'pn_1']),
            'api.vapi.ai/phone-number' => Http::response([['id' => 'pn_1', 'number' => '+14155550100', 'provider' => 'twilio']]),
        ]);

        $this->actingAs($this->admin())->get('/admin/phone-numbers')->assertOk();
        $this->actingAs($this->admin())->post('/admin/phone-numbers/assign', ['phoneNumberId' => 'pn_1', 'userId' => $user->id])->assertSessionHas('success');

        $bot = $user->bot->fresh();
        $this->assertSame(['pn_1', '+14155550100'], [$bot->vapi_phone_number_id, $bot->phone_number]);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/phone-number/pn_1') && $r['assistantId'] === 'asst_1');
        $this->assertSame('pn_1', app(VapiService::class)->outboundNumberId($bot)); // its own number wins over the platform one
    }

    public function test_phone_calls_are_logged_with_direction_and_number(): void
    {
        $user = $this->customer(10);
        $this->postJson('/api/webhooks/vapi', ['message' => [
            'type' => 'end-of-call-report', 'durationSeconds' => 60,
            'call' => ['id' => 'ph_1', 'type' => 'inboundPhoneCall', 'customer' => ['number' => '+923001234567'], 'metadata' => ['botId' => $user->bot->id]],
            'artifact' => ['messages' => [['role' => 'user', 'message' => 'Hello']]],
        ]])->assertOk();

        $call = $user->bot->calls()->firstOrFail();
        $this->assertSame(['inbound', '+923001234567'], [$call->direction, $call->customer_number]);
        $this->assertSame('inbound', $call->toRow()['direction']);
    }
}
