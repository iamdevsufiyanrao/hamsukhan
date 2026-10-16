<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VoiceSampleTest extends TestCase
{
    use RefreshDatabase;

    private function sample(bool $active = true): VoiceSample
    {
        Storage::put('voice-samples/a.mp3', 'RIFF-pretend-audio-bytes-0123456789');

        return VoiceSample::create(['name' => 'Sara', 'language' => 'English', 'path' => 'voice-samples/a.mp3', 'is_active' => $active]);
    }

    public function test_admin_uploads_a_sample_to_the_private_disk(): void
    {
        Storage::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/voice-samples', [
            'name' => 'Sara', 'label' => 'Warm', 'language' => 'English',
            'audio' => UploadedFile::fake()->create('sara.mp3', 200, 'audio/mpeg'),
        ])->assertSessionHas('success');

        $s = VoiceSample::sole();
        Storage::assertExists($s->path);
        $this->assertStringStartsWith('voice-samples/', $s->path);

        $this->actingAs(User::factory()->create())->post('/admin/voice-samples', [])->assertForbidden();
    }

    public function test_landing_lists_only_active_samples_with_signed_urls(): void
    {
        Storage::fake();
        $on = $this->sample();
        $this->sample(false);

        $this->get('/')->assertInertia(fn ($page) => $page
            ->component('Landing')
            ->has('samples', 1)
            ->where('samples.0.id', $on->id)
            ->where('samples.0.src', fn ($src) => str_contains($src, 'signature=') && str_contains($src, 'expires='))
            ->missing('samples.0.path'));
    }

    public function test_play_needs_a_valid_signature_and_returns_scrambled_bytes(): void
    {
        Storage::fake();
        $s = $this->sample();
        $raw = Storage::get($s->path);

        $this->get("/voice-samples/{$s->id}/play")->assertForbidden();

        $res = $this->get(URL::temporarySignedRoute('voice-samples.play', now()->addMinutes(5), ['sample' => $s->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $body = $res->getContent();
        $this->assertNotSame($raw, $body);
        $key = hex2bin($s->scrambleKey());
        $this->assertSame($raw, substr($body ^ str_repeat($key, 10), 0, strlen($raw))); // the page can undo it

        $this->travel(10)->minutes();
        $this->get(URL::temporarySignedRoute('voice-samples.play', now()->subMinute(), ['sample' => $s->id]))->assertForbidden();
    }

    public function test_hidden_samples_cannot_be_played_by_visitors(): void
    {
        Storage::fake();
        $s = $this->sample(false);
        $this->get(URL::temporarySignedRoute('voice-samples.play', now()->addMinutes(5), ['sample' => $s->id]))->assertNotFound();
    }
}
