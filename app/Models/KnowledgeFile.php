<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeFile extends Model
{
    protected $fillable = ['name', 'path', 'size_bytes', 'mime', 'provider_file_id', 'status'];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
