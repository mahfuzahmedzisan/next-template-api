<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Contracts\Scout\DefinesSearchIndex;
use App\Enums\UserRole;
use App\Models\Concerns\ConfiguresScoutSearch;
use App\Support\Scout\SearchIndexDefinition;
use App\Support\Scout\SearchIndexField;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * `role` is intentionally excluded from #[Fillable] - it must never be
 * settable from request input (registration, profile update, etc.). It is
 * only ever written directly by trusted server code (UserSeeder, future
 * admin-promotion tooling).
 */
#[Fillable(['name', 'email', 'password', 'avatar', 'email_notifications', 'push_notifications', 'theme', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements DefinesSearchIndex
{
    /** @use HasFactory<UserFactory> */
    use ConfiguresScoutSearch, HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'email_notifications' => 'boolean',
            'push_notifications' => 'boolean',
        ];
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function avatarUrl(): ?string
    {
        if (! $this->avatar) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar);
    }

    public static function searchIndexDefinition(): SearchIndexDefinition
    {
        return new SearchIndexDefinition([
            SearchIndexField::string('name'),
            SearchIndexField::string('email'),
            SearchIndexField::string('role', filterable: true),
            SearchIndexField::int64('created_at', sortable: true),
        ]);
    }

    /**
     * Secrets (password, 2FA, tokens) are intentionally absent.
     *
     * @return array<string, mixed>
     */
    public function searchableDocument(): array
    {
        $role = $this->role instanceof UserRole ? $this->role->value : (string) $this->role;

        return [
            'name' => (string) $this->name,
            'email' => (string) $this->email,
            'role' => $role,
            'created_at' => $this->created_at?->getTimestamp() ?? 0,
        ];
    }
}
