<?php

namespace Tests;

use App\Models\User;
use App\Services\BotProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A customer with a provisioned bot and the given wallet balance (USD). */
    protected function customer(float $balance = 0): User
    {
        $user = User::factory()->create();
        app(BotProvisioner::class)->provision($user);
        $user->forceFill(['balance' => $balance])->save();

        return $user->fresh();
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }
}
