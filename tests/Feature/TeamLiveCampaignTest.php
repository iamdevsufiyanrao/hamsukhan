<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\LiveCall;
use App\Models\User;
use App\Services\OutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Live calls (listen / control), team members and roles, knowledge history, outbound campaigns. */
class TeamLiveCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function live(float $balance = 10): User
    {
        config(['hamsukhan.vapi.private_key' => 'vk', 'hamsukhan.vapi.public_key' => 'pk', 'hamsukhan.vapi.phone_number_id' => 'pn_platform']);
        $user = $this->customer($balance);
        $user->bot->forceFill(['vapi_assistant_id' => 'asst_1', 'sync_error' => null])->save();

        return $user->fresh();
    }

    private function statusUpdate(int $botId, string $status): array
    {
        return ['message' => ['type' => 'status-update', 'status' => $status, 'call' => [
            'id' => 'live_1', 'type' => 'inboundPhoneCall', 'customer' => ['number' => '+923001234567'], 'metadata' => ['botId' => $botId],
            'monitor' => ['listenUrl' => 'wss://phone-call-websocket.aws-us-west-2-backend-production1.vapi.ai/live_1/listen', 'controlUrl' => 'https://phone-call-websocket.aws-us-west-2-backend-production1.vapi.ai/live_1/control'],
        ]]];
    }

    public function test_live_calls_appear_can_be_controlled_and_disappear(): void
    {
        $user = $this->live();
        Http::fake(['*.vapi.ai/live_1/control' => Http::response(['ok' => true])]);

        $this->postJson('/api/webhooks/vapi', $this->statusUpdate($user->bot->id, 'in-progress'))->assertOk();
        $this->actingAs($user)->getJson('/live-calls')->assertOk()
            ->assertJsonPath('calls.0.direction', 'inbound')->assertJsonPath('calls.0.listenUrl', fn ($u) => str_ends_with($u, '/listen'))
            ->assertJsonMissingPath('calls.0.controlUrl');
        $id = LiveCall::firstOrFail()->id;

        $this->actingAs($user)->postJson("/live-calls/{$id}/say", ['text' => 'Hold on please'])->assertOk();
        $this->actingAs($user)->postJson("/live-calls/{$id}/end")->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/control') && $r['type'] === 'say' && $r['content'] === 'Hold on please');
        Http::assertSent(fn ($r) => $r['type'] === 'end-call');

        $this->actingAs($this->customer())->postJson("/live-calls/{$id}/end")->assertNotFound(); // someone else's call

        $this->postJson('/api/webhooks/vapi', $this->statusUpdate($user->bot->id, 'ended'))->assertOk();
        $this->assertSame(0, LiveCall::count());
    }

    public function test_control_url_must_be_vapi(): void
    {
        $user = $this->live();
        $call = $user->bot->liveCalls()->create(['vapi_call_id' => 'x', 'control_url' => 'https://evil.example/control', 'started_at' => now()]);
        $this->actingAs($user)->postJson("/live-calls/{$call->id}/end")->assertStatus(502);
    }

    public function test_team_members_share_the_bot_with_role_limits(): void
    {
        Mail::fake();
        $owner = $this->customer(10);
        $this->actingAs($owner)->post('/settings/team', ['name' => 'Hina', 'email' => 'hina@salon.pk', 'role' => 'operator'])->assertSessionHas('success');
        $member = User::where('email', 'hina@salon.pk')->firstOrFail();
        $this->assertSame([$owner->id, 'operator'], [$member->owner_id, $member->team_role]);
        $this->assertSame($owner->bot->id, $member->bot->id);

        $booking = $owner->bot->bookings()->create(['customer_name' => 'A', 'request' => 'x']);
        $this->actingAs($member)->get('/bookings')->assertOk();
        $this->actingAs($member)->patch("/bookings/{$booking->id}", ['status' => 'contacted'])->assertSessionHas('success');
        $this->actingAs($member)->patch('/bot-studio/config', ['greeting' => 'Hi', 'language' => 'English', 'prompt' => 'x', 'maxCallMinutes' => 5])->assertSessionHas('error');
        $this->actingAs($member)->get('/billing')->assertRedirect('/dashboard');
        $this->actingAs($member)->post('/settings/team', ['name' => 'X', 'email' => 'x@x.pk', 'role' => 'admin'])->assertForbidden();

        // Viewer can't even change a lead; admin can edit the bot
        $member->forceFill(['team_role' => 'viewer'])->save();
        $this->actingAs($member)->patch("/bookings/{$booking->id}", ['status' => 'done'])->assertSessionHas('error');
        $member->forceFill(['team_role' => 'admin'])->save();
        $this->actingAs($member)->patch('/bot-studio/config', ['greeting' => 'Hi', 'language' => 'English', 'prompt' => 'Be kind', 'maxCallMinutes' => 5])->assertSessionHas('success');

        // Suspending the owner locks the team out too; deleting a member leaves the owner's bot alone
        $this->assertFalse($member->fresh()->isSuspended());
        $owner->forceFill(['suspended_at' => now()])->save();
        $this->assertTrue($member->fresh()->isSuspended());
        $owner->forceFill(['suspended_at' => null])->save();
        $this->actingAs($owner)->delete("/settings/team/{$member->id}")->assertSessionHas('success');
        $this->assertNull(User::find($member->id));
        $this->assertNotNull($owner->fresh()->bot);
    }

    public function test_knowledge_history_keeps_and_restores_versions(): void
    {
        $user = $this->customer();
        $original = $user->bot->prompt;
        $this->actingAs($user)->patch('/bot-studio/config', ['greeting' => 'Hello new', 'language' => 'English', 'prompt' => 'New rules', 'maxCallMinutes' => 5]);
        $this->actingAs($user)->patch('/bot-studio/knowledge', ['quickFacts' => 'Open 10 to 9']);

        $versions = $user->bot->knowledgeVersions()->latest('id')->get();
        $this->assertCount(2, $versions);
        $first = $versions->last();
        $this->assertSame($original, $first->prompt);

        $this->actingAs($user)->post("/bot-studio/versions/{$first->id}/restore")->assertSessionHas('success');
        $bot = $user->bot->fresh();
        $this->assertSame($original, $bot->prompt);
        $this->assertNull($bot->quick_facts);
        $this->assertSame(3, $bot->knowledgeVersions()->count()); // the state before restoring is kept too

        $this->actingAs($this->customer())->post("/bot-studio/versions/{$first->id}/restore")->assertNotFound();
    }

    public function test_campaign_calls_contacts_in_hours_and_marks_them_done(): void
    {
        $user = $this->live();
        Http::fake(['api.vapi.ai/call' => Http::sequence()->push(['id' => 'c_1'])->push(['id' => 'c_2'])]);

        $this->actingAs($user)->post('/campaigns', [
            'name' => 'Eid offer', 'purpose' => 'Offer 20% off', 'firstMessage' => 'Salam {name}!', 'perRun' => 2, 'consent' => true,
            'contacts' => "Ayesha, 0300 1234567\nBilal; 0321 7654321\n0300 1234567\nnot a number",
        ])->assertSessionHas('success');
        $campaign = Campaign::firstOrFail();
        $this->assertSame(2, $campaign->contacts()->count()); // duplicate and bad line skipped

        $this->actingAs($user)->post('/campaigns', ['name' => 'x', 'purpose' => 'x', 'firstMessage' => 'x', 'perRun' => 1, 'contacts' => '03001234567'])->assertSessionHasErrors('consent');

        $outbound = app(OutboundService::class);
        $this->assertSame(0, $outbound->runCampaigns()); // still a draft
        $this->actingAs($user)->patch("/campaigns/{$campaign->id}", ['status' => 'running']);

        Carbon::setTestNow(Carbon::parse('2026-10-26 23:00', 'Asia/Karachi')); // night, no hours set: waits
        $this->assertSame(0, $outbound->runCampaigns());
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00', 'Asia/Karachi'));
        $this->assertSame(2, $outbound->runCampaigns());
        Http::assertSent(fn ($r) => $r['customer']['number'] === '+923001234567' && $r['assistantOverrides']['firstMessage'] === 'Salam Ayesha!');

        $contact = $campaign->contacts()->where('vapi_call_id', 'c_1')->firstOrFail();
        $this->postJson('/api/webhooks/vapi', ['message' => [
            'type' => 'end-of-call-report', 'durationSeconds' => 40,
            'call' => ['id' => 'c_1', 'type' => 'outboundPhoneCall', 'metadata' => ['botId' => $user->bot->id, 'campaignContactId' => $contact->id]],
            'artifact' => ['messages' => [['role' => 'user', 'message' => 'Ji']]],
        ]])->assertOk();
        $this->assertSame('done', $contact->fresh()->status);
    }
}
