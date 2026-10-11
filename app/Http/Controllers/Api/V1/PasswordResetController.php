<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Services\DeviceSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class PasswordResetController extends Controller
{
    /**
     * Always respond the same way regardless of whether the email exists -
     * leaking account existence through response differences is exactly the
     * kind of thing a "best security" auth flow should not do.
     */
    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return $this->apiSuccess('If an account exists for that email, a password reset link has been sent.');
    }

    public function reset(ResetPasswordRequest $request, ResetsUserPasswords $resetter, DeviceSessions $devices): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($resetter, $request, $devices) {
                $resetter->reset($user, $request->only('password', 'password_confirmation'));
                $devices->revokeAll($user, DeviceSessions::REASON_PASSWORD_RESET);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->apiSuccess('Password has been reset successfully.');
    }
}
