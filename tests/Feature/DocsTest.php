<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guide_is_public_and_knows_the_app_url(): void
    {
        $this->get('/docs')->assertOk()->assertInertia(fn ($p) => $p->component('Docs')->where('appUrl', rtrim(config('app.url'), '/'))->has('site'));
    }

    /** The signature recipe the docs teach must match what we actually send. */
    public function test_documented_signature_recipe_matches_the_connector(): void
    {
        $body = '{"event":"booking.created","id":"x"}';
        $header = \Hamsukhan\Connector\Signature::sign($body, 'whsec_test', 1700000000);
        parse_str(str_replace(',', '&', $header), $sig);
        $this->assertSame(hash_hmac('sha256', $sig['t'].'.'.$body, 'whsec_test'), $sig['v1']);
    }
}
