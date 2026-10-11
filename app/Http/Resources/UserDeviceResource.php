<?php

namespace App\Http\Resources;

use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @mixin UserDevice
 */
class UserDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();
        $lastActive = $this->token?->last_used_at ?? $this->last_active_at ?? $this->created_at;

        if ($this->last_active_at !== null && $lastActive->lessThan($this->last_active_at)) {
            $lastActive = $this->last_active_at;
        }

        return [
            'id' => $this->id,
            'browser' => $this->browser,
            'platform' => $this->platform,
            'device_type' => $this->device_type,
            'ip_address' => $this->ip_address,
            'remember' => $this->remember,
            'status' => $this->status(),
            'is_current' => $current instanceof PersonalAccessToken
                && $this->token_id !== null
                && (int) $this->token_id === (int) $current->getKey(),
            'signed_in_at' => $this->created_at?->toIso8601String(),
            'last_active_at' => $lastActive?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,
        ];
    }
}
