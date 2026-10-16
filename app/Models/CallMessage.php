<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['role', 'text', 'position'];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
