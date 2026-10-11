<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use App\Support\ClientContext;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Every Sanctum token belongs to one device row. Revoking goes through here
 * so the row keeps when and why the device lost access after its token is
 * deleted.
 */
class DeviceSessions
{
    public const REASON_SIGNED_OUT = 'signed_out';

    public const REASON_REVOKED = 'revoked';

    public const REASON_SIGNED_OUT_EVERYWHERE = 'signed_out_everywhere';

    public const REASON_PASSWORD_CHANGED = 'password_changed';

    public const REASON_PASSWORD_RESET = 'password_reset';

    public function record(User $user, PersonalAccessToken $token, Request $request, bool $remember): UserDevice
    {
        $userAgent = ClientContext::userAgent($request);

        return UserDevice::query()->create([
            'user_id' => $user->id,
            'token_id' => $token->id,
            'ip_address' => ClientContext::ip($request),
            'user_agent' => $userAgent,
            ...UserAgent::parse($userAgent),
            'remember' => $remember,
            'last_active_at' => now(),
            'expires_at' => $token->expires_at,
        ]);
    }

    public function revoke(UserDevice $device, string $reason): void
    {
        DB::transaction(function () use ($device, $reason): void {
            $token = $device->token;

            $device->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
                'last_active_at' => $this->latest($device->last_active_at, $token?->last_used_at),
            ])->save();

            $token?->delete();
        });
    }

    public function revokeToken(PersonalAccessToken $token, string $reason): void
    {
        $device = UserDevice::query()->where('token_id', $token->id)->first();

        if ($device === null) {
            $token->delete();

            return;
        }

        $device->setRelation('token', $token);
        $this->revoke($device, $reason);
    }

    /**
     * Revokes every token the user holds, optionally keeping one.
     *
     * @return int the number of tokens revoked
     */
    public function revokeAll(User $user, string $reason, ?PersonalAccessToken $except = null): int
    {
        return DB::transaction(function () use ($user, $reason, $except): int {
            $tokens = $user->tokens()
                ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
                ->get(['id', 'last_used_at']);

            if ($tokens->isEmpty()) {
                return 0;
            }

            $lastUsed = $tokens->pluck('last_used_at', 'id');

            UserDevice::query()
                ->whereIn('token_id', $tokens->modelKeys())
                ->get()
                ->each(function (UserDevice $device) use ($reason, $lastUsed): void {
                    $device->forceFill([
                        'revoked_at' => now(),
                        'revoked_reason' => $reason,
                        'last_active_at' => $this->latest($device->last_active_at, $lastUsed[$device->token_id] ?? null),
                    ])->save();
                });

            PersonalAccessToken::query()->whereKey($tokens->modelKeys())->delete();

            return $tokens->count();
        });
    }

    private function latest(?Carbon $first, ?Carbon $second): ?Carbon
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return $first->greaterThan($second) ? $first : $second;
    }
}
