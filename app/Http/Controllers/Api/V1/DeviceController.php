<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserDeviceResource;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-in history for the authenticated user. Every lookup is scoped to
 * `$user->devices()`, so another user's device id resolves to a 404.
 */
class DeviceController extends Controller
{
    private const HISTORY_PER_PAGE = 15;

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $this->currentTokenId($user);

        $devices = $user->devices()
            ->active()
            ->with('token')
            ->latest('id')
            ->get()
            ->sortByDesc(fn (UserDevice $device): bool => $currentId !== null && (int) $device->token_id === $currentId)
            ->values();

        return $this->apiSuccess('Devices retrieved successfully.', [
            'devices' => UserDeviceResource::collection($devices)->resolve(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $page = $user->devices()
            ->inactive()
            ->with('token')
            ->latest('id')
            ->paginate(self::HISTORY_PER_PAGE);

        return $this->apiSuccess('Device history retrieved successfully.', [
            'devices' => UserDeviceResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function revoke(Request $request, int $device, DeviceSessions $devices): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $user->devices()->with('token')->findOrFail($device);

        if ($model->token_id !== null && (int) $model->token_id === $this->currentTokenId($user)) {
            return $this->apiUnprocessable('This is the device you are using. Use Log out instead.');
        }

        if (! $model->isActive()) {
            return $this->apiUnprocessable('This device is already signed out.');
        }

        $devices->revoke($model, DeviceSessions::REASON_REVOKED);

        return $this->apiSuccess('Device signed out.');
    }

    public function forget(Request $request, int $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $user->devices()->findOrFail($device);

        if ($model->isActive()) {
            return $this->apiUnprocessable('Sign this device out before removing it from the history.');
        }

        $model->delete();

        return $this->apiSuccess('Removed from the history.');
    }

    public function clearHistory(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $removed = $user->devices()->inactive()->delete();

        return $this->apiSuccess('History cleared.', [
            'removed' => $removed,
        ]);
    }

    private function currentTokenId(User $user): ?int
    {
        $current = $user->currentAccessToken();

        return $current instanceof PersonalAccessToken ? (int) $current->getKey() : null;
    }
}
