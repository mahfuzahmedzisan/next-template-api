<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('registers a user and returns a bearer token', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Registered successfully.')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'admin@example.com')
        ->assertJsonStructure([
            'data' => [
                'access_token',
                'session_ends_at',
                'user' => ['id', 'name', 'email', 'avatar'],
            ],
        ])
        ->assertJsonMissingPath('data.refresh_token');

    $this->assertDatabaseHas('users', [
        'email' => 'admin@example.com',
    ]);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('logs in with valid credentials and returns a bearer token', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Logged in successfully.')
        ->assertJsonPath('data.user.email', 'admin@example.com')
        ->assertJsonStructure([
            'data' => ['access_token', 'token_type', 'session_ends_at'],
        ]);
});

it('rejects invalid login credentials', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('returns the authenticated user for me', function (): void {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->json('data');

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'admin@example.com');
});

it('requires a bearer token for me', function (): void {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('deletes the current token on logout', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->json('data');

    $this->withToken($login['access_token'])
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    $this->assertDatabaseCount('personal_access_tokens', 0);

    // Clear in-process guard cache so the next request re-validates the Bearer token.
    auth()->forgetGuards();

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('has no refresh endpoint', function (): void {
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'anything'])
        ->assertNotFound();
});

it('rejects a token after it expires', function (): void {
    User::factory()->create([
        'email' => 'expire@example.com',
        'password' => 'Password1!',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'expire@example.com',
        'password' => 'Password1!',
    ])->json('data');

    $this->travel(13)->hours();

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('throttles login after five attempts', function (): void {
    User::factory()->create([
        'email' => 'locked@example.com',
        'password' => 'Password1!',
    ]);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'locked@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'locked@example.com',
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
});

it('requires a two factor challenge when the user has 2FA enabled', function (): void {
    $user = User::factory()->create([
        'email' => '2fa@example.com',
        'password' => 'Password1!',
    ]);

    enableConfirmedTwoFactor($user, 'recovery-code-alpha');

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => '2fa@example.com',
        'password' => 'Password1!',
    ]);

    $login->assertOk()
        ->assertJsonPath('data.two_factor', true)
        ->assertJsonStructure(['data' => ['challenge_token']])
        ->assertJsonMissingPath('data.access_token');

    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $login->json('data.challenge_token'),
        'recovery_code' => 'recovery-code-alpha',
    ])->assertOk()
        ->assertJsonPath('data.user.email', '2fa@example.com')
        ->assertJsonStructure([
            'data' => ['access_token', 'token_type', 'session_ends_at'],
        ]);
});

it('keeps the password out of the two factor challenge token', function (): void {
    $user = User::factory()->create([
        'email' => '2fa-sealed@example.com',
        'password' => 'Password1!',
    ]);

    enableConfirmedTwoFactor($user, 'recovery-code-gamma');

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => '2fa-sealed@example.com',
        'password' => 'Password1!',
    ])->json('data.challenge_token');

    $payload = json_decode(Crypt::decryptString($challenge), true);

    expect($payload)->toBeArray()
        ->toHaveKeys(['user_id', 'remember', 'nonce', 'expires_at'])
        ->not->toHaveKey('password')
        ->not->toHaveKey('email');
});

it('issues a 12 hour session unless remember me is checked', function (): void {
    User::factory()->create([
        'email' => 'hours@example.com',
        'password' => 'Password1!',
    ]);

    $short = $this->postJson('/api/v1/auth/login', [
        'email' => 'hours@example.com',
        'password' => 'Password1!',
    ])->assertOk()->json('data.session_ends_at');

    expect($short)->toBeGreaterThan(now()->addHours(11)->getTimestamp())
        ->toBeLessThan(now()->addHours(13)->getTimestamp());

    $long = $this->postJson('/api/v1/auth/login', [
        'email' => 'hours@example.com',
        'password' => 'Password1!',
        'remember' => true,
    ])->assertOk()->json('data.session_ends_at');

    expect($long)->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->toBeLessThan(now()->addDays(31)->getTimestamp());

    $expiries = PersonalAccessToken::query()
        ->orderBy('id')
        ->pluck('expires_at')
        ->map(fn ($value) => $value->getTimestamp())
        ->all();

    expect($expiries)->toBe([$short, $long]);
});

it('deletes every token when the password is reset', function (): void {
    $user = User::factory()->create([
        'email' => 'reset@example.com',
        'password' => 'Password1!',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'reset@example.com',
        'password' => 'Password1!',
    ])->json('data');

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => Password::createToken($user),
        'email' => 'reset@example.com',
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 0);

    auth()->forgetGuards();

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('rejects an already-used two factor challenge token', function (): void {
    $user = User::factory()->create([
        'email' => '2fa-reuse@example.com',
        'password' => 'Password1!',
    ]);

    enableConfirmedTwoFactor($user, 'recovery-code-beta');

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => '2fa-reuse@example.com',
        'password' => 'Password1!',
    ]);

    $payload = [
        'challenge_token' => $login->json('data.challenge_token'),
        'recovery_code' => 'recovery-code-beta',
    ];

    $this->postJson('/api/v1/auth/two-factor-challenge', $payload)->assertOk();

    $this->postJson('/api/v1/auth/two-factor-challenge', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge_token']);
});

it('burns a two factor challenge token after five wrong codes', function (): void {
    $user = User::factory()->create(['email' => '2fa-burn@example.com', 'password' => 'Password1!']);
    enableConfirmedTwoFactor($user, 'recovery-code-delta');

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => '2fa-burn@example.com',
        'password' => 'Password1!',
    ])->json('data.challenge_token');

    foreach (range(1, 5) as $attempt) {
        $this->withHeader('X-Client-IP', "203.0.113.{$attempt}")
            ->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $token, 'recovery_code' => 'wrong-'.$attempt])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    $this->withHeader('X-Client-IP', '203.0.113.9')
        ->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $token, 'recovery_code' => 'recovery-code-delta'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge_token']);
});

it('limits two factor guesses per account across fresh challenge tokens', function (): void {
    $user = User::factory()->create(['email' => '2fa-account@example.com', 'password' => 'Password1!']);
    enableConfirmedTwoFactor($user, 'recovery-code-epsilon');

    foreach (range(1, 10) as $attempt) {
        $token = $this->withHeader('X-Client-IP', "198.51.100.{$attempt}")->postJson('/api/v1/auth/login', [
            'email' => '2fa-account@example.com',
            'password' => 'Password1!',
        ])->json('data.challenge_token');

        $this->withHeader('X-Client-IP', "198.51.100.{$attempt}")
            ->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $token, 'recovery_code' => 'wrong'])
            ->assertUnprocessable();
    }

    $token = $this->withHeader('X-Client-IP', '198.51.100.50')->postJson('/api/v1/auth/login', [
        'email' => '2fa-account@example.com',
        'password' => 'Password1!',
    ])->json('data.challenge_token');

    $this->withHeader('X-Client-IP', '198.51.100.50')
        ->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $token, 'recovery_code' => 'recovery-code-epsilon'])
        ->assertTooManyRequests();
});

it('keys the register limiter on the visitor IP the BFF forwards', function (): void {
    foreach (range(1, 3) as $attempt) {
        $this->withHeader('X-Client-IP', '192.0.2.10')->postJson('/api/v1/auth/register', [])->assertStatus(422);
    }

    $this->withHeader('X-Client-IP', '192.0.2.10')->postJson('/api/v1/auth/register', [])->assertTooManyRequests();
    $this->withHeader('X-Client-IP', '192.0.2.11')->postJson('/api/v1/auth/register', [])->assertStatus(422);
});
