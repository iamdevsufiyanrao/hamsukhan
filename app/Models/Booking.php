<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    /** Lead pipeline: new, contacted, confirmed (booked), done (won), lost, spam. */
    public const STATUSES = ['new', 'contacted', 'confirmed', 'done', 'lost', 'spam'];

    public const TYPES = ['booking', 'order', 'callback'];

    protected $fillable = ['call_id', 'type', 'source','customer_name', 'phone', 'request', 'preferred_time', 'details', 'status'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(IntegrationDelivery::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
