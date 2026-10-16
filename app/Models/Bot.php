<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Bot extends Model
{
    protected $fillable = [
        'business_name', 'website', 'greeting', 'end_message', 'language', 'prompt', 'quick_facts', 'capture_fields',
        'max_call_minutes', 'voice_id', 'preset_voice', 'widget', 'vapi_assistant_id', 'synced_at',
        'is_active', 'installed_at',
    ];

    protected function casts(): array
    {
        return [
            'widget' => 'array',
            'capture_fields' => 'array',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
            'installed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Bot $bot) {
            $bot->public_key ??= (string) Str::uuid();
            $bot->widget ??= self::defaultWidget();
        });
    }

    public static function defaultWidget(): array
    {
        return [
            'position' => 'bottom-right',
            'offset' => 24,
            'width' => 230,
            'height' => 54,
            'states' => [
                'idle' => ['title' => 'Have a question?', 'sub' => 'Talk to our AI receptionist', 'color' => '#E8590C', 'iconUrl' => ''],
                'loading' => ['title' => 'Connecting…', 'sub' => 'Please wait', 'color' => '#B7791F', 'iconUrl' => ''],
                'active' => ['title' => 'On call', 'sub' => 'Tap to hang up', 'color' => '#2563EB', 'iconUrl' => ''],
                'error' => ['title' => "Couldn't connect", 'sub' => 'Please try again', 'color' => '#D92D20', 'iconUrl' => ''],
            ],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function knowledgeFiles(): HasMany
    {
        return $this->hasMany(KnowledgeFile::class);
    }

    public function voiceLabel(): string
    {
        if ($this->voice) {
            return $this->voice->name.' · cloned';
        }
        $preset = collect(config('hamsukhan.preset_voices'))->firstWhere('id', $this->preset_voice);

        return $preset['name'] ?? 'Not set';
    }

    /** The system prompt actually sent to the LLM: instructions + quick facts. */
    public function systemPrompt(): string
    {
        $facts = trim((string) $this->quick_facts);
        $collect = \App\Support\CaptureFields::promptHint($this->capture_fields ?? []);

        return trim($this->prompt)
            .($collect !== '' ? "\n\n".$collect : '')
            .($facts !== '' ? "\n\nFacts you must know:\n".$facts : '');
    }
}
