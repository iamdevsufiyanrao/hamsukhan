<?php

namespace Hamsukhan\Connector\Laravel;

use Hamsukhan\Connector\Event;

/** Fired for every verified delivery. Listen to it if you prefer events over a handler class. */
class EventReceived
{
    public function __construct(public readonly Event $event) {}
}
