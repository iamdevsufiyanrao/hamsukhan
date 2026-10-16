<?php

namespace App\Console\Commands;

use App\Services\Integrations\IntegrationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hamsukhan:deliver-integrations')]
#[Description('Retry booking/order deliveries to customer software that are due')]
class DeliverIntegrations extends Command
{
    public function handle(IntegrationDispatcher $dispatcher): int
    {
        $n = $dispatcher->deliverDue();
        $this->info("Attempted {$n} deliveries.");

        return self::SUCCESS;
    }
}
