<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The bot's greeting / instructions / quick facts as they were before a change. */
class KnowledgeVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'greeting', 'prompt', 'quick_facts', 'note'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
