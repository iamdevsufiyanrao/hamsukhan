<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Integration extends Model
{
    public const KINDS = ['webhook', 'form'];

    public const EVENTS = ['booking.created', 'order.created', 'callback.created'];

    protected $fillable = ['name', 'kind', 'url', 'events', 'field_map', 'secret', 'is_active', 'last_delivery_at', 'last_status'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'field_map' => 'array',
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_delivery_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Integration $i) => $i->secret ??= self::newSecret());
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(IntegrationDelivery::class);
    }

    public function listensTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }
}
