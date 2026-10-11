<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $token_id
 * @property Carbon|null $last_active_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon $created_at
 */
#[Fillable([
    'user_id',
    'token_id',
    'ip_address',
    'user_agent',
    'browser',
    'platform',
    'device_type',
    'remember',
    'last_active_at',
    'expires_at',
    'revoked_at',
    'revoked_reason',
])]
class UserDevice extends Model
{
    use MassPrunable;

    /** Revoked and expired devices stay in the history this long. */
    public const HISTORY_DAYS = 30;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected function casts(): array
    {
        return [
            'remember' => 'boolean',
            'last_active_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->whereNotNull('token_id')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotNull('revoked_at')
            ->orWhereNull('token_id')
            ->orWhere('expires_at', '<=', now()));
    }

    public function status(): string
    {
        if ($this->revoked_at !== null || $this->token_id === null) {
            return self::STATUS_REVOKED;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_ACTIVE;
    }

    public function isActive(): bool
    {
        return $this->status() === self::STATUS_ACTIVE;
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(self::HISTORY_DAYS);

        return static::query()->where(fn (Builder $q) => $q->where('revoked_at', '<=', $cutoff)
            ->orWhere('expires_at', '<=', $cutoff));
    }
}
