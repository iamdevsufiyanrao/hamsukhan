<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'password', 'avatar_path', 'timezone', 'terms_accepted_at', 'notify_email', 'notify_whatsapp', 'whatsapp_number', 'notify_daily_summary', 'monthly_cap'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Same defaults as the migration, so a freshly created model has them in memory too. */
    protected $attributes = [
        'is_admin' => false,
        'timezone' => 'Asia/Karachi',
        'balance' => 0,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'balance' => 'decimal:4',
            'notify_email' => 'boolean',
            'notify_whatsapp' => 'boolean',
            'notify_daily_summary' => 'boolean',
            'monthly_cap' => 'decimal:2',
        ];
    }

    public function bot(): HasOne
    {
        return $this->hasOne(Bot::class);
    }

    public function voices(): HasMany
    {
        return $this->hasMany(Voice::class);
    }

    public function topUps(): HasMany
    {
        return $this->hasMany(TopUp::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(collect($parts)->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('')) ?: '?';
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function minutesLeft(): int
    {
        return (int) floor((float) $this->balance / config('hamsukhan.rate_per_minute'));
    }
}
