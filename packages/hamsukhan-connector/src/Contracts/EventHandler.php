<?php

namespace Hamsukhan\Connector\Contracts;

use Hamsukhan\Connector\Event;

interface EventHandler
{
    /**
     * Save the booking/order in your system.
     * Return your own record id (e.g. "ORD-1042"), Hamsukhan shows it next to the booking. Return null if you have none.
     * Throw an exception to make Hamsukhan retry later.
     */
    public function handle(Event $event): ?string;
}
