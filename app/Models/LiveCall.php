<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A call happening right now. Monitor URLs are capability links: never shown to anyone but the bot's account. */
class LiveCall extends Model
{
    protected $fillable = ['vapi_call_id', 'direction', 'customer_number', 'listen_url', 'control_url', 'started_at'];

    protected $hidden = ['control_url'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'listen_url' => 'encrypted', 'control_url' => 'encrypted'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
