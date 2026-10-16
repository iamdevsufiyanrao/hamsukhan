<?php

namespace Hamsukhan\Connector;

use InvalidArgumentException;

/**
 * One delivery from Hamsukhan, e.g.
 * {
 *   "event": "order.created", "id": "<delivery uuid>", "test": false, "created_at": "...",
 *   "bot": {"id": 1, "business": "Smart Geeks"},
 *   "booking": {"id": 9, "type": "order", "status": "new", "customer_name": "...", "phone": "...",
 *               "request": "...", "preferred_time": "...", "details": {"items": "...", "address": "..."}},
 *   "call": {"id": 42, "started_at": "...", "duration_seconds": 95, "summary": "..."}
 * }
 */
final class Event
{
    private function __construct(public readonly array $payload) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['event'], $data['booking'])) {
            throw new InvalidArgumentException('Not a Hamsukhan event payload.');
        }

        return new self($data);
    }

    /** "booking.created" | "order.created" | "callback.created" */
    public function type(): string
    {
        return $this->payload['event'];
    }

    /** Unique per delivery, store it to ignore duplicates if a retry arrives. */
    public function id(): string
    {
        return (string) ($this->payload['id'] ?? '');
    }

    public function isTest(): bool
    {
        return (bool) ($this->payload['test'] ?? false);
    }

    public function isOrder(): bool
    {
        return $this->type() === 'order.created';
    }

    public function customerName(): ?string
    {
        return $this->payload['booking']['customer_name'] ?? null;
    }

    public function phone(): ?string
    {
        return $this->payload['booking']['phone'] ?? null;
    }

    public function request(): ?string
    {
        return $this->payload['booking']['request'] ?? null;
    }

    public function preferredTime(): ?string
    {
        return $this->payload['booking']['preferred_time'] ?? null;
    }

    /** A custom capture field, e.g. $event->field('address'). */
    public function field(string $key, mixed $default = null): mixed
    {
        return $this->payload['booking']['details'][$key] ?? $default;
    }

    public function booking(): array
    {
        return $this->payload['booking'];
    }

    public function call(): array
    {
        return $this->payload['call'] ?? [];
    }
}
