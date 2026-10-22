<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    public const STATUSES = ['draft', 'running', 'paused', 'done'];

    protected $fillable = ['name', 'purpose', 'first_message', 'status', 'per_run', 'consent_confirmed_at'];

    protected function casts(): array
    {
        return ['consent_confirmed_at' => 'datetime'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CampaignContact::class);
    }
}
