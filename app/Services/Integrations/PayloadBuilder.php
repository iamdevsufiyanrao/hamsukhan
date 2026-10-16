<?php

namespace App\Services\Integrations;

use App\Models\Booking;
use App\Models\Integration;
use App\Support\CaptureFields;

/** Builds what we send to the customer's software: the full JSON event, or mapped form fields. */
class PayloadBuilder
{
    public static function eventFor(Booking $booking): string
    {
        return $booking->type.'.created';
    }

    public function event(Booking $booking, string $deliveryId, bool $isTest = false): array
    {
        $booking->loadMissing('call', 'bot');

        return [
            'event' => self::eventFor($booking),
            'id' => $deliveryId,
            'test' => $isTest,
            'created_at' => now()->toIso8601String(),
            'bot' => ['id' => $booking->bot->id, 'business' => $booking->bot->business_name],
            'booking' => [
                'id' => $booking->id,
                'type' => $booking->type,
                'status' => $booking->status,
                'customer_name' => $booking->customer_name,
                'phone' => $booking->phone,
                'request' => $booking->request,
                'preferred_time' => $booking->preferred_time,
                'details' => (object) ($booking->details ?? []),
            ],
            'call' => $booking->call ? [
                'id' => $booking->call->id,
                'started_at' => $booking->call->started_at->toIso8601String(),
                'duration_seconds' => $booking->call->duration_seconds,
                'summary' => $booking->call->summary,
            ] : null,
        ];
    }

    /** Values a form mapping can pick from, keyed by source name (shown in the mapping UI). */
    public static function sources(array $captureFields): array
    {
        $s = CaptureFields::STANDARD + [
            'booking_type' => 'Type (booking / order / callback)',
            'booking_id' => 'Hamsukhan booking id',
            'call_summary' => 'Call summary',
            'call_started_at' => 'Call time',
            'business' => 'Your business name',
        ];
        foreach ($captureFields as $f) {
            $s['details.'.$f['key']] = $f['label'];
        }

        return $s;
    }

    /** Apply the field map: [{target: "customer", source: "customer_name"}, {target: "source", value: "phone-bot"}] */
    public function formFields(Integration $integration, array $event): array
    {
        $flat = [
            'customer_name' => $event['booking']['customer_name'],
            'phone' => $event['booking']['phone'],
            'request' => $event['booking']['request'],
            'preferred_time' => $event['booking']['preferred_time'],
            'booking_type' => $event['booking']['type'],
            'booking_id' => $event['booking']['id'],
            'call_summary' => $event['call']['summary'] ?? null,
            'call_started_at' => $event['call']['started_at'] ?? null,
            'business' => $event['bot']['business'],
        ];
        foreach ((array) $event['booking']['details'] as $k => $v) {
            $flat['details.'.$k] = $v;
        }

        $out = [];
        foreach ($integration->field_map ?? [] as $row) {
            $target = trim((string) ($row['target'] ?? ''));
            if ($target === '') {
                continue;
            }
            $out[$target] = array_key_exists('value', $row) && ($row['source'] ?? '') === '' ? (string) $row['value'] : (string) ($flat[$row['source'] ?? ''] ?? '');
        }

        return $out;
    }
}
