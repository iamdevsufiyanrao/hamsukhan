<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voice extends Model
{
    protected $fillable = ['name', 'provider', 'provider_voice_id', 'sample_path', 'sample_seconds', 'status', 'consent_at'];

    protected function casts(): array
    {
        return ['consent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
