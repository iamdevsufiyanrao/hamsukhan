<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IntegrationDelivery extends Model
{
    protected $fillable = ['event', 'booking_id', 'is_test', 'payload', 'status', 'attempts', 'response_code', 'response_body', 'external_reference', 'next_attempt_at', 'delivered_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_test' => 'boolean',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (IntegrationDelivery $d) => $d->uuid ??= (string) Str::uuid());
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
