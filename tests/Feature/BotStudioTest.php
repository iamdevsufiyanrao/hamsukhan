<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BotStudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_config_saves_and_validates(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->patch('/bot-studio/config', [
            'greeting' => 'Hello!', 'endMessage' => 'Bye', 'language' => 'Urdu', 'prompt' => 'Be nice.', 'maxCallMinutes' => 5,
        ])->assertSessionHasNoErrors();

        $bot = $user->bot->fresh();
        $this->assertSame('Urdu', $bot->language);
        $this->assertSame(5, $bot->max_call_minutes);

        $this->actingAs($user)->patch('/bot-studio/config', ['greeting' => '', 'language' => 'Klingon', 'prompt' => 'x', 'maxCallMinutes' => 7])
            ->assertSessionHasErrors(['greeting', 'language', 'maxCallMinutes']);
    }

    public function test_clone_voice_requires_consent_and_length_then_becomes_active(): void
    {
        Storage::fake();
        $user = $this->customer();
        $sample = fn () => UploadedFile::fake()->create('sample.webm', 300, 'audio/webm');

        $this->actingAs($user)->post('/voices', ['name' => 'Me', 'sample' => $sample(), 'seconds' => 10, 'consent' => true])->assertSessionHasErrors('seconds');
        $this->actingAs($user)->post('/voices', ['name' => 'Me', 'sample' => $sample(), 'seconds' => 40])->assertSessionHasErrors('consent');

        $this->actingAs($user)->post('/voices', ['name' => 'Me', 'sample' => $sample(), 'seconds' => 40, 'consent' => true])->assertSessionHasNoErrors();
        $voice = $user->voices()->firstOrFail();
        $this->assertStringStartsWith('sim_', $voice->provider_voice_id);
        $this->assertSame($voice->id, $user->bot->fresh()->voice_id);
    }

    public function test_deleting_active_voice_falls_back(): void
    {
        $user = $this->customer();
        $voice = $user->voices()->create(['name' => 'Me', 'provider_voice_id' => 'sim_1', 'consent_at' => now()]);
        $user->bot->update(['voice_id' => $voice->id, 'preset_voice' => null]);

        $this->actingAs($user)->delete("/voices/{$voice->id}")->assertSessionHas('success');
        $bot = $user->bot->fresh();
        $this->assertNull($bot->voice_id);
        $this->assertSame(config('hamsukhan.preset_voices.0.id'), $bot->preset_voice);
    }

    public function test_cannot_select_or_delete_someone_elses_voice(): void
    {
        $other = $this->customer();
        $voice = $other->voices()->create(['name' => 'Theirs', 'provider_voice_id' => 'sim_2', 'consent_at' => now()]);
        $me = $this->customer();

        $this->actingAs($me)->patch('/bot-studio/voice', ['voiceId' => $voice->id])->assertSessionHasErrors('voiceId');
        $this->actingAs($me)->delete("/voices/{$voice->id}")->assertNotFound();
    }

    public function test_widget_colors_are_validated(): void
    {
        $user = $this->customer();
        $widget = $user->bot->widget;
        $widget['states']['idle']['color'] = 'orange';
        $this->actingAs($user)->patch('/bot-studio/widget', $widget)->assertSessionHasErrors('states.idle.color');

        $widget['states']['idle']['color'] = '#123456';
        $this->actingAs($user)->patch('/bot-studio/widget', $widget)->assertSessionHasNoErrors();
        $this->assertSame('#123456', $user->bot->fresh()->widget['states']['idle']['color']);
    }

    public function test_booking_status_update_is_scoped_to_owner(): void
    {
        $owner = $this->customer();
        $booking = $owner->bot->bookings()->create(['customer_name' => 'A', 'request' => 'B']);

        $this->actingAs($this->customer())->patch("/bookings/{$booking->id}", ['status' => 'done'])->assertNotFound();
        $this->actingAs($owner)->patch("/bookings/{$booking->id}", ['status' => 'done'])->assertSessionHas('success');
        $this->assertSame('done', $booking->fresh()->status);
    }
}
