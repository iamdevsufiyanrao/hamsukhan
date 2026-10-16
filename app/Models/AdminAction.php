<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only audit trail of what super admins did to customer accounts. */
class AdminAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'target_user_id', 'action', 'meta', 'ip'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(User $admin, ?User $target, string $action, array $meta = []): self
    {
        return self::create([
            'admin_id' => $admin->id,
            'target_user_id' => $target?->id,
            'action' => $action,
            'meta' => $meta ?: null,
            'ip' => request()?->ip(),
        ]);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
