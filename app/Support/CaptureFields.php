<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Custom fields a bot collects on top of the standard ones (name, phone, request, preferred time).
 * Shape of one field: {key, label, type: text|number|date|time|list, required: bool, hint?: string}
 */
class CaptureFields
{
    public const TYPES = ['text', 'number', 'date', 'time', 'list'];

    /** Always captured; every booking row has them as columns. */
    public const STANDARD = [
        'customer_name' => 'Customer name',
        'phone' => 'Phone',
        'request' => 'Request',
        'preferred_time' => 'Preferred time',
    ];

    public static function presets(): array
    {
        return [
            'reception' => [],
            'restaurant' => [
                ['key' => 'order_type', 'label' => 'Pickup or delivery', 'type' => 'text', 'required' => true, 'hint' => 'pickup or delivery'],
                ['key' => 'items', 'label' => 'Items', 'type' => 'list', 'required' => true, 'hint' => 'each item with size and quantity'],
                ['key' => 'address', 'label' => 'Delivery address', 'type' => 'text', 'required' => false],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'text', 'required' => false],
            ],
            'clinic' => [
                ['key' => 'doctor', 'label' => 'Doctor', 'type' => 'text', 'required' => false],
                ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                ['key' => 'time', 'label' => 'Time', 'type' => 'time', 'required' => true],
                ['key' => 'reason', 'label' => 'Reason for visit', 'type' => 'text', 'required' => false],
            ],
            'salon' => [
                ['key' => 'service', 'label' => 'Service', 'type' => 'text', 'required' => true],
                ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true],
                ['key' => 'time', 'label' => 'Time', 'type' => 'time', 'required' => true],
                ['key' => 'stylist', 'label' => 'Preferred stylist', 'type' => 'text', 'required' => false],
            ],
        ];
    }

    /** Clean user input: slug keys, known types, drop blanks and duplicates/standard keys, max 12. */
    public static function normalize(array $fields): array
    {
        $seen = array_keys(self::STANDARD);
        $out = [];
        foreach ($fields as $f) {
            $label = trim((string) ($f['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = Str::snake(Str::ascii($f['key'] ?? '') ?: Str::ascii($label));
            $key = trim(preg_replace('/[^a-z0-9_]/', '', $key), '_') ?: 'field';
            if (in_array($key, $seen, true)) {
                continue;
            }
            $seen[] = $key;
            $out[] = [
                'key' => $key,
                'label' => mb_substr($label, 0, 40),
                'type' => in_array($f['type'] ?? '', self::TYPES, true) ? $f['type'] : 'text',
                'required' => (bool) ($f['required'] ?? false),
                'hint' => mb_substr(trim((string) ($f['hint'] ?? '')), 0, 80) ?: null,
            ];
            if (count($out) === 12) {
                break;
            }
        }

        return $out;
    }

    /** JSON-schema properties for Vapi's structured-data extraction. */
    public static function schema(array $fields): array
    {
        $props = [];
        foreach ($fields as $f) {
            $desc = $f['label'].($f['hint'] ? ': '.$f['hint'] : '').($f['required'] ? ' (ask the caller for this)' : '');
            $props[$f['key']] = match ($f['type']) {
                'number' => ['type' => 'number', 'description' => $desc],
                'list' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => $desc],
                'date' => ['type' => 'string', 'description' => $desc.', as YYYY-MM-DD'],
                'time' => ['type' => 'string', 'description' => $desc.', as HH:MM, 24h'],
                default => ['type' => 'string', 'description' => $desc],
            };
        }

        return $props;
    }

    /** Keep only known keys; lists become comma-separated text for storage. */
    public static function extract(array $fields, array $raw): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (! array_key_exists($f['key'], $raw) || $raw[$f['key']] === null || $raw[$f['key']] === '') {
                continue;
            }
            $v = $raw[$f['key']];
            $out[$f['key']] = is_array($v) ? implode(', ', array_map('strval', $v)) : (is_scalar($v) ? (string) $v : json_encode($v));
        }

        return $out;
    }

    /** Prompt lines that tell the bot what it must collect. */
    public static function promptHint(array $fields): string
    {
        $required = array_filter($fields, fn ($f) => $f['required']);
        if (! $required) {
            return '';
        }

        return 'When taking a booking or order, always collect: name, phone number, '.implode(', ', array_map(fn ($f) => mb_strtolower($f['label']), $required)).'.';
    }
}
