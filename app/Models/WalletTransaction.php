<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['type', 'amount', 'balance_after', 'description', 'call_id', 'top_up_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
