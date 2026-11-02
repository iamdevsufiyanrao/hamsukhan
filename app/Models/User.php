<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'password', 'avatar_path', 'timezone', 'terms_accepted_at', 'notify_email', 'notify_whatsapp', 'whatsapp_number', 'notify_daily_summary'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Same defaults as the migration, so a freshly created model has them in memory too. */
    protected $attributes = [
        'is_admin' => false,
        'timezone' => 'Asia/Karachi',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'plan_expires_at' => 'datetime',
            'notify_email' => 'boolean',
            'notify_whatsapp' => 'boolean',
            'notify_daily_summary' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * The store (bot) this user is working on: the one they switched to, else the account's first.
     * Team members work on their owner's bots.
     */
    public function bot(): HasOne
    {
        $relation = $this->hasOne(Bot::class, 'user_id', $this->owner_id ? 'owner_id' : 'id');
        if ($this->current_bot_id) {
            $relation->orderByRaw('CASE WHEN bots.id = ? THEN 0 ELSE 1 END', [$this->current_bot_id]);
        }

        return $relation->orderBy('bots.id');
    }

    /** Every store of the account, oldest first (the first N are the ones the plan covers). */
    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class, 'user_id', $this->owner_id ? 'owner_id' : 'id')->orderBy('bots.id');
    }

    public const TEAM_ROLES = ['admin', 'operator', 'viewer'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'owner_id');
    }

    /** The account that owns the bot, voices, calendar and wallet: the owner for a team member, else this user. */
    public function account(): User
    {
        return $this->owner_id ? ($this->owner ?? $this) : $this;
    }

    public function isMember(): bool
    {
        return $this->owner_id !== null;
    }

    public function googleCalendar(): HasOne
    {
        return $this->hasOne(GoogleCalendar::class);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function voices(): HasMany
    {
        return $this->hasMany(Voice::class);
    }

    public function topUps(): HasMany
    {
        return $this->hasMany(TopUp::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null || ($this->owner_id !== null && $this->owner?->suspended_at !== null);
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

    /** Key of the paid-up plan (basic | business), or null when there is none or it ran out. Ask on the account (owner). */
    public function activePlan(): ?string
    {
        return $this->plan && $this->plan_expires_at?->isFuture() && config("hamsukhan.plans.{$this->plan}") ? $this->plan : null;
    }

    /** Limits of the active plan; without one, the Basic limits (so a new owner can set up their one store). */
    public function planLimits(): array
    {
        return config('hamsukhan.plans.'.($this->activePlan() ?? 'basic'));
    }
}
