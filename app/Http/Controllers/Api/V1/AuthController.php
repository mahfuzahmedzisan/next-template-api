<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\AuthTokenService;
use App\Services\DeviceSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class AuthController extends Controller
{
    /** How long a pending two-factor challenge stays redeemable. */
    private const CHALLENGE_TTL_MINUTES = 5;

    public function __construct(
        private readonly AuthTokenService $tokens,
    ) {}

    public function register(Request $request, CreatesNewUsers $creator, ApplicationSettings $settings): JsonResponse
    {
        if (! $settings->registrationEnabled()) {
            return $this->apiUnprocessable('Public registration is turned off.');
        }

        $user = $creator->create($request->all());

        $token = $this->tokens->issue($user, $request);

        return $this->apiCreated(
            'Registered successfully.',
            $this->tokenPayload($token, $user),
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->apiSuccess('Two factor authentication required.', [
                'two_factor' => true,
                'challenge_token' => $this->createChallengeToken(
                    $user,
                    $request->boolean('remember'),
                ),
            ]);
        }

        $token = $this->tokens->issue($user, $request, $request->boolean('remember'));

        return $this->apiSuccess(
            'Logged in successfully.',
            $this->tokenPayload($token, $user),
        );
    }

    /**
     * Completes a login that was paused for two-factor verification.
     *
     * This API is stateless (Bearer tokens, no PHP session), so it can't use
     * Fortify's own session-based `login.id` flow. Instead the first factor
     * (password) is proven once and the user id is sealed into a short-lived,
     * authenticated (`Crypt`), single-use challenge token.
     */
    public function twoFactorChallenge(
        TwoFactorChallengeRequest $request,
        TwoFactorAuthenticationProvider $provider,
    ): JsonResponse {
        $payload = $this->decryptChallengeToken($request->string('challenge_token')->toString());

        $usedKey = "2fa-challenge-used:{$payload['nonce']}";

        if (Cache::has($usedKey)) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This login challenge has already been used.'],
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->find($payload['user_id']);

        if (! $user || ! $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This login challenge is no longer valid.'],
            ]);
        }

        $valid = $this->verifyCode($user, $provider, $request->input('code'), $request->input('recovery_code'));

        if (! $valid) {
            throw ValidationException::withMessages([
                'code' => ['The provided two factor authentication code was invalid.'],
            ]);
        }

        Cache::put($usedKey, true, now()->addMinutes(self::CHALLENGE_TTL_MINUTES + 1));

        $token = $this->tokens->issue($user, $request, $payload['remember']);

        return $this->apiSuccess(
            'Logged in successfully.',
            $this->tokenPayload($token, $user),
        );
    }

    public function me(Request $request): JsonResponse
    {
        return $this->apiSuccess('Authenticated user retrieved successfully.', [
            'user' => new UserResource($request->user()),
        ]);
    }

    public function logout(Request $request, DeviceSessions $devices): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $devices->revokeToken($token, DeviceSessions::REASON_SIGNED_OUT);
        }

        return $this->apiSuccess('Logged out successfully.');
    }

    private function createChallengeToken(User $user, bool $remember): string
    {
        return Crypt::encrypt([
            'user_id' => $user->id,
            'remember' => $remember,
            'nonce' => Str::random(40),
            'expires_at' => now()->addMinutes(self::CHALLENGE_TTL_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * @return array{user_id: int, remember: bool, nonce: string, expires_at: int}
     */
    private function decryptChallengeToken(string $token): array
    {
        try {
            $payload = Crypt::decrypt($token);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This login challenge has expired or is invalid.'],
            ]);
        }

        if (
            ! is_array($payload)
            || ! isset($payload['user_id'], $payload['nonce'], $payload['expires_at'])
        ) {
            throw new BadRequestException('Malformed two-factor challenge token.');
        }

        if ($payload['expires_at'] < now()->getTimestamp()) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This login challenge has expired. Please log in again.'],
            ]);
        }

        $payload['remember'] = (bool) ($payload['remember'] ?? false);

        return $payload;
    }

    private function verifyCode(
        User $user,
        TwoFactorAuthenticationProvider $provider,
        ?string $code,
        ?string $recoveryCode,
    ): bool {
        if (filled($recoveryCode)) {
            $codes = $user->recoveryCodes();

            if (in_array($recoveryCode, $codes, true)) {
                $user->replaceRecoveryCode($recoveryCode);

                return true;
            }

            return false;
        }

        if (blank($code) || blank($user->two_factor_secret)) {
            return false;
        }

        return $provider->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            $code,
        );
    }

    /**
     * @param  array{token_type: string, access_token: string, session_ends_at: int}  $token
     * @return array<string, mixed>
     */
    private function tokenPayload(array $token, User $user): array
    {
        return [
            ...$token,
            'user' => new UserResource($user),
        ];
    }
}
