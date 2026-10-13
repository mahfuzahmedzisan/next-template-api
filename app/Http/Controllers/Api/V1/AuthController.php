<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Audit;
use App\Audit\AuditEvent;
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
use Illuminate\Support\Facades\RateLimiter;
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

    /** Wrong codes allowed on one challenge token before it is burned. */
    private const TOKEN_ATTEMPTS = 5;

    /** Wrong codes allowed per account, across tokens, within the decay window. */
    private const USER_ATTEMPTS = 10;

    private const USER_DECAY_SECONDS = 900;

    public function __construct(
        private readonly AuthTokenService $tokens,
    ) {}

    public function register(Request $request, CreatesNewUsers $creator, ApplicationSettings $settings): JsonResponse
    {
        if (! $settings->registrationEnabled()) {
            return $this->apiUnprocessable('Public registration is turned off.');
        }

        // The "Account registered" entry below replaces the model's "created" entry.
        $user = activity()->withoutLogging(fn () => $creator->create($request->all()));

        Audit::log(AuditEvent::Registered, subject: $user, causer: $user, properties: [
            'name' => $user->name,
            'email' => $user->email,
        ]);

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
            Audit::log(
                AuditEvent::LoginFailed,
                subject: $user,
                properties: [
                    'email' => $credentials['email'],
                    'reason' => $user ? 'wrong_password' : 'unknown_email',
                ],
                subjectLabel: $user ? null : $credentials['email'],
            );

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

        Audit::log(AuditEvent::LoginSucceeded, subject: $user, causer: $user, properties: [
            'remember' => $request->boolean('remember'),
            'two_factor' => false,
        ]);

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

        // Logging in again mints a fresh challenge token, so the per-token
        // limiter alone would let one account be guessed indefinitely.
        $userKey = "2fa:user:{$payload['user_id']}";

        if (RateLimiter::tooManyAttempts($userKey, self::USER_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many two-factor attempts. Try again later.',
            ], 429, ['Retry-After' => RateLimiter::availableIn($userKey)]);
        }

        /** @var User|null $user */
        $user = User::query()->find($payload['user_id']);

        if (! $user || ! $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This login challenge is no longer valid.'],
            ]);
        }

        $usedRecoveryCode = filled($request->input('recovery_code'));
        $valid = $this->verifyCode($user, $provider, $request->input('code'), $request->input('recovery_code'));

        if (! $valid) {
            RateLimiter::hit($userKey, self::USER_DECAY_SECONDS);

            $failureKey = "2fa-challenge-failures:{$payload['nonce']}";
            Cache::add($failureKey, 0, now()->addMinutes(self::CHALLENGE_TTL_MINUTES + 1));
            $failures = (int) Cache::increment($failureKey);

            if ($failures >= self::TOKEN_ATTEMPTS) {
                Cache::put($usedKey, true, now()->addMinutes(self::CHALLENGE_TTL_MINUTES + 1));
            }

            Audit::log(AuditEvent::TwoFactorChallengeFailed, subject: $user, properties: [
                'method' => $usedRecoveryCode ? 'recovery_code' : 'authenticator',
            ]);

            throw ValidationException::withMessages([
                'code' => ['The provided two factor authentication code was invalid.'],
            ]);
        }

        // Claiming the nonce atomically stops two parallel requests with the same code.
        if (! Cache::add($usedKey, true, now()->addMinutes(self::CHALLENGE_TTL_MINUTES + 1))) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This login challenge has already been used.'],
            ]);
        }

        RateLimiter::clear($userKey);

        $token = $this->tokens->issue($user, $request, $payload['remember']);

        Audit::log(AuditEvent::LoginSucceeded, subject: $user, causer: $user, properties: [
            'remember' => $payload['remember'],
            'two_factor' => true,
            'method' => $usedRecoveryCode ? 'recovery_code' : 'authenticator',
        ]);

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
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user instanceof User) {
            Audit::log(AuditEvent::Logout, subject: $user, causer: $user);
        }

        if ($token instanceof PersonalAccessToken) {
            $devices->revokeToken($token, DeviceSessions::REASON_SIGNED_OUT);
        }

        return $this->apiSuccess('Logged out successfully.');
    }

    private function createChallengeToken(User $user, bool $remember): string
    {
        return Crypt::encryptString((string) json_encode([
            'user_id' => $user->id,
            'remember' => $remember,
            'nonce' => Str::random(40),
            'expires_at' => now()->addMinutes(self::CHALLENGE_TTL_MINUTES)->getTimestamp(),
        ]));
    }

    /**
     * @return array{user_id: int, remember: bool, nonce: string, expires_at: int}
     */
    private function decryptChallengeToken(string $token): array
    {
        // decryptString + JSON: the client sends this token, so never unserialize it.
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
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
            // Locked so two parallel requests can't both redeem the same code.
            return (bool) Cache::lock("2fa-recovery:{$user->id}", 10)->block(5, function () use ($user, $recoveryCode): bool {
                $fresh = $user->fresh() ?? $user;

                foreach ($fresh->recoveryCodes() as $code) {
                    if (is_string($code) && hash_equals($code, (string) $recoveryCode)) {
                        $fresh->replaceRecoveryCode($code);

                        return true;
                    }
                }

                return false;
            });
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
