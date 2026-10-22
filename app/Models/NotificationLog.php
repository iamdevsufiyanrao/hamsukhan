<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One owner alert per channel. The unique (dedupe_key, channel) index makes every alert send at most once. */
class NotificationLog extends Model
{
    protected $fillable = ['user_id', 'kind', 'channel', 'dedupe_key', 'status', 'error'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
