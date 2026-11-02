<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Super admin's logo and home-page intro video, and the brand-kit defaults. */
class BrandMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_kit_defaults_are_shared(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($p) => $p
            ->where('site.logoUrl', null)
            ->where('site.introVideo.src', '/brand/hamsukhan-intro.mp4')
            ->where('site.introVideo.vertical', true));
        $this->assertFileExists(public_path('brand/hamsukhan-icon.svg'));
        $this->assertFileExists(public_path('brand/hamsukhan-intro.mp4'));
        $this->assertStringContainsString('/brand/og-image.png', $this->get('/')->getContent());
    }

    public function test_admin_uploads_and_removes_logo_and_video(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/settings/media/site_logo', ['file' => UploadedFile::fake()->image('logo.png', 512, 512)])->assertSessionHas('success');
        $this->actingAs($admin)->post('/admin/settings/media/intro_video', ['file' => UploadedFile::fake()->create('intro.mp4', 3000, 'video/mp4')])->assertSessionHas('success');
        $this->actingAs($admin)->patch('/admin/settings/site', ['intro_vertical' => false])->assertSessionHas('success');

        $page = $this->get('/');
        $logo = $page->viewData('page')['props']['site']['logoUrl'];
        $this->assertStringContainsString('/storage/site/site_logo-', $logo);
        $page->assertInertia(fn ($p) => $p->where('site.introVideo.vertical', false)->where('site.introVideo.youtubeId', null));
        $this->assertCount(2, Storage::disk('public')->files('site'));

        // Replacing deletes the old file; removing goes back to the default.
        $this->actingAs($admin)->post('/admin/settings/media/site_logo', ['file' => UploadedFile::fake()->image('logo2.png', 512, 512)]);
        $this->assertCount(2, Storage::disk('public')->files('site'));
        $this->actingAs($admin)->delete('/admin/settings/media/site_logo')->assertSessionHas('success');
        $this->get('/')->assertInertia(fn ($p) => $p->where('site.logoUrl', null));
        $this->assertCount(1, Storage::disk('public')->files('site'));
    }

    public function test_uploads_are_checked(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings/media/site_logo', ['file' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('file');
        $this->actingAs($admin)->post('/admin/settings/media/intro_video', ['file' => UploadedFile::fake()->create('big.mp4', 210000, 'video/mp4')])->assertSessionHasErrors('file');
        $this->actingAs($admin)->post('/admin/settings/media/whatever', ['file' => UploadedFile::fake()->image('x.png')])->assertNotFound();
        $this->actingAs($this->customer())->post('/admin/settings/media/site_logo', ['file' => UploadedFile::fake()->image('x.png')])->assertForbidden();
    }

    public function test_youtube_link_wins_and_can_be_switched_off(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch('/admin/settings/site', ['intro_youtube' => 'https://evil.example/watch?v=abc'])->assertSessionHasErrors('intro_youtube');
        $this->actingAs($admin)->patch('/admin/settings/site', ['intro_youtube' => 'https://youtu.be/dQw4w9WgXcQ'])->assertSessionHas('success');
        $this->get('/')->assertInertia(fn ($p) => $p->where('site.introVideo.youtubeId', 'dQw4w9WgXcQ')->where('site.introVideo.src', null));

        $this->actingAs($admin)->patch('/admin/settings/site', ['intro_enabled' => false]);
        $this->get('/')->assertInertia(fn ($p) => $p->where('site.introVideo', null));
    }
}
