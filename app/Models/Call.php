<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Call extends Model
{
    protected $fillable = [
        'vapi_call_id', 'title', 'outcome_tag', 'status', 'ended_reason', 'is_test', 'started_at', 'ended_at',
        'duration_seconds', 'cost', 'recording_url', 'summary', 'unanswered', 'after_hours',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'cost' => 'decimal:4',
            'unanswered' => 'array',
            'after_hours' => 'boolean',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CallMessage::class)->orderBy('position');
    }

    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class);
    }

    /** Compact shape for tables. */
    public function toRow(): array
    {
        $firstUser = $this->relationLoaded('messages') ? $this->messages->firstWhere('role', 'user') : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'tag' => $this->outcome_tag,
            'status' => $this->status,
            'isTest' => $this->is_test,
            'startedAt' => $this->started_at->toIso8601String(),
            'duration' => $this->duration_seconds,
            'cost' => (float) $this->cost,
            'quote' => $firstUser?->text,
        ];
    }

    /** Full shape for the call drawer. */
    public function toDetail(): array
    {
        return $this->toRow() + [
            'summary' => $this->summary,
            'recordingUrl' => $this->recording_url,
            'endedReason' => $this->ended_reason,
            'transcript' => $this->messages->map(fn (CallMessage $m) => [$m->role, $m->text])->all(),
        ];
    }
}
