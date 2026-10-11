<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AvatarStorageService;
use App\Support\AllowedAvatar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Throwable;

class ProfileController extends Controller
{
    public function update(Request $request, UpdatesUserProfileInformation $updater): JsonResponse
    {
        $updater->update($request->user(), $request->all());

        return $this->apiSuccess('Profile updated successfully.', [
            'user' => new UserResource($request->user()->fresh()),
        ]);
    }

    /**
     * Signs out every other session; the one that changed the password stays.
     */
    public function updatePassword(Request $request, UpdatesUserPasswords $updater): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updater->update($user, $request->all());
        $this->deleteOtherTokens($user);

        return $this->apiSuccess('Password updated successfully.');
    }

    /**
     * Signs out every other device after the password is confirmed.
     */
    public function destroyOtherSessions(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $revoked = $this->deleteOtherTokens($user);

        return $this->apiSuccess('Signed out of other devices.', [
            'revoked' => $revoked,
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        if ($request->exists('timezone') && in_array($request->input('timezone'), ['', 'auto'], true)) {
            $request->merge(['timezone' => null]);
        }

        $validated = $request->validate([
            'email_notifications' => ['sometimes', 'boolean'],
            'push_notifications' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', 'string', Rule::in(['light', 'dark', 'system'])],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->fill($validated)->save();

        return $this->apiSuccess('Preferences updated successfully.', [
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function updateAvatar(Request $request, AvatarStorageService $avatars): JsonResponse
    {
        $request->validate([
            'avatar' => AllowedAvatar::rules(),
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $user = $avatars->store($user, $request->file('avatar'));
        } catch (Throwable $exception) {
            report($exception);

            return $this->apiUnprocessable(
                $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to process avatar.',
            );
        }

        return $this->apiSuccess('Avatar updated successfully.', [
            'user' => new UserResource($user),
        ]);
    }

    public function destroyAvatar(Request $request, AvatarStorageService $avatars): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user = $avatars->delete($user);

        return $this->apiSuccess('Avatar removed successfully.', [
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Deletes the authenticated user's account. Deletes every API token
     * first so no token outlives the account it was issued for.
     */
    public function destroy(Request $request, AvatarStorageService $avatars): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $avatars->purge($user);

        $user->tokens()->delete();
        $user->delete();

        return $this->apiSuccess('Account deleted successfully.');
    }

    private function deleteOtherTokens(User $user): int
    {
        $current = $user->currentAccessToken();

        return $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();
    }
}
