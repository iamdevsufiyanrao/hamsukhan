<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/** Bot Studio → Add to website: WordPress plugin, emailed steps and "check my website". */
class InstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_wordpress_plugin_has_the_key_inside_and_is_valid_php(): void
    {
        $user = $this->customer();
        $res = $this->actingAs($user)->get('/bot-studio/wordpress-plugin.zip')->assertOk();
        $this->assertStringContainsString('hamsukhan-voice-receptionist.zip', $res->headers->get('content-disposition'));

        $zipPath = tempnam(sys_get_temp_dir(), 'wpt');
        file_put_contents($zipPath, file_get_contents($res->baseResponse->getFile()->getPathname()));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $php = $zip->getFromName('hamsukhan-voice-receptionist/hamsukhan-voice-receptionist.php');
        $this->assertNotFalse($zip->getFromName('hamsukhan-voice-receptionist/readme.txt'));
        $zip->close();

        $this->assertStringContainsString("define('HAMSUKHAN_DEFAULT_KEY', '{$user->bot->public_key}')", $php);
        $this->assertStringContainsString("define('HAMSUKHAN_HOST', '".rtrim(url('/'), '/')."')", $php);
        $this->assertStringContainsString('Plugin Name:', $php);
        $this->assertStringNotContainsString('{{', $php);

        $file = tempnam(sys_get_temp_dir(), 'wpp').'.php';
        file_put_contents($file, $php);
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_steps_are_emailed_to_the_web_person(): void
    {
        config(['mail.default' => 'array']);
        $user = $this->customer();
        $this->actingAs($user)->post('/bot-studio/install/email', ['email' => 'dev@example.com', 'note' => 'Today please'])->assertSessionHas('success');

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $text = $sent[0]->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString($user->bot->public_key, $text);
        $this->assertStringContainsString('Custom Liquid', $text);
        $this->assertStringContainsString('Today please', $text);

        $this->actingAs($user)->post('/bot-studio/install/email', ['email' => 'nope'])->assertSessionHasErrors('email');
    }

    public function test_check_finds_the_widget_and_marks_the_store_installed(): void
    {
        $user = $this->customer();
        $key = $user->bot->public_key;
        Http::fake([
            'shop-ok.example.com*' => Http::response("<html><body><script src=\"x/widget.js\" data-bot=\"{$key}\" defer></script></body></html>"),
            'shop-no.example.com*' => Http::response('<html><body>hello</body></html>'),
        ]);
        // DNS for the fake hosts: UrlGuard looks them up, so point them at a public address.
        config(['hamsukhan.integrations.allow_private_urls' => true]);

        $this->actingAs($user)->postJson('/bot-studio/install/check', ['url' => 'shop-no.example.com'])->assertOk()->assertJson(['found' => false, 'installed' => false]);
        $this->actingAs($user)->postJson('/bot-studio/install/check', ['url' => 'https://shop-ok.example.com'])->assertOk()->assertJson(['found' => true, 'installed' => true]);
        $this->assertNotNull($user->bot->fresh()->installed_at);
    }

    public function test_check_never_reaches_private_addresses(): void
    {
        config(['hamsukhan.integrations.allow_private_urls' => false]);
        Http::fake();
        $this->actingAs($this->customer())->postJson('/bot-studio/install/check', ['url' => 'http://127.0.0.1/admin'])
            ->assertOk()->assertJson(['found' => false])->assertJsonPath('message', 'Private or local network addresses are not allowed.');
        Http::assertNothingSent();
    }

    public function test_bot_studio_offers_the_wizard(): void
    {
        $this->actingAs($this->customer())->get('/bot-studio')->assertOk()->assertInertia(fn ($p) => $p->where('install.shopifyEnabled', false)->has('install.shopifyShops', 0));
    }
}
