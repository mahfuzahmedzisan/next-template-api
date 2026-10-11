<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Audit;
use App\Audit\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AvatarStorageService;
use App\Services\DeviceSessions;
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
    public function updatePassword(Request $request, UpdatesUserPasswords $updater, DeviceSessions $devices): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updater->update($user, $request->all());
        $revoked = $devices->revokeAll($user, DeviceSessions::REASON_PASSWORD_CHANGED, $this->currentToken($user));

        Audit::log(AuditEvent::PasswordChanged, subject: $user, causer: $user, properties: [
            'signed_out_devices' => $revoked,
        ]);

        return $this->apiSuccess('Password updated successfully.');
    }

    /**
     * Signs out every other device after the password is confirmed, and
     * this one too when `include_current` is set.
     */
    public function destroyOtherSessions(Request $request, DeviceSessions $devices): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'include_current' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $includeCurrent = $request->boolean('include_current');
        $revoked = $devices->revokeAll(
            $user,
            DeviceSessions::REASON_SIGNED_OUT_EVERYWHERE,
            $includeCurrent ? null : $this->currentToken($user),
        );
        $others = $includeCurrent ? max(0, $revoked - 1) : $revoked;

        Audit::log(AuditEvent::SignedOutEverywhere, subject: $user, causer: $user, properties: [
            'signed_out_devices' => $others,
            'included_current' => $includeCurrent,
        ]);

        return $this->apiSuccess('Signed out of other devices.', [
            'revoked' => $others,
            'signed_out_current' => $includeCurrent,
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
            $user = activity()->withoutLogging(fn () => $avatars->store($user, $request->file('avatar')));
            Audit::log(AuditEvent::AvatarUpdated, subject: $user, causer: $user);
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
        $hadAvatar = filled($user->avatar);
        $user = activity()->withoutLogging(fn () => $avatars->delete($user));

        if ($hadAvatar) {
            Audit::log(AuditEvent::AvatarRemoved, subject: $user, causer: $user);
        }

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

        Audit::log(AuditEvent::AccountDeleted, subject: $user, causer: $user, properties: [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'active_tokens' => $user->tokens()->count(),
        ]);

        $avatars->purge($user);

        $user->tokens()->delete();
        $user->disableLogging()->delete();

        return $this->apiSuccess('Account deleted successfully.');
    }

    private function currentToken(User $user): ?PersonalAccessToken
    {
        $current = $user->currentAccessToken();

        return $current instanceof PersonalAccessToken ? $current : null;
    }
}
