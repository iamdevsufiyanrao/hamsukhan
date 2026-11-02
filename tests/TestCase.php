<?php

namespace Tests;

use App\Models\User;
use App\Services\BotProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A customer with a provisioned bot, on the given plan for a month (null = no paid plan). */
    protected function customer(?string $plan = 'basic'): User
    {
        $user = User::factory()->create();
        app(BotProvisioner::class)->provision($user);
        if ($plan) {
            $user->forceFill(['plan' => $plan, 'plan_expires_at' => now()->addMonth()])->save();
        }

        return $user->fresh();
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }
}
