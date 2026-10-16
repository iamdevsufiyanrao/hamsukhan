<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopUp extends Model
{
    public const METHODS = ['jazzcash', 'easypaisa', 'bank'];

    protected $fillable = ['amount_usd', 'amount_pkr', 'method', 'reference', 'proof_path', 'status', 'reviewed_by', 'reviewed_at', 'admin_note'];

    protected function casts(): array
    {
        return [
            'amount_usd' => 'decimal:2',
            'amount_pkr' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function methodLabel(): string
    {
        return config("hamsukhan.payment_accounts.{$this->method}.label", ucfirst($this->method));
    }
}
