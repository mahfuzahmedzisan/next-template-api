<?php

use App\Models\User;
use App\Services\ImageConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

uses(RefreshDatabase::class);

/**
 * @return array{access_token: string, token_type: string, session_ends_at: int, user: array<string, mixed>}
 */
function loginAs(string $email, string $password = 'Password1!'): array
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
    ])->json('data');
}

it('updates the authenticated user profile', function (): void {
    User::factory()->create([
        'email' => 'profile@example.com',
        'password' => 'Password1!',
        'name' => 'Before',
    ]);

    $login = loginAs('profile@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile', [
            'name' => 'After',
            'email' => 'profile-updated@example.com',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile', [
            'name' => 'After',
            'email' => 'profile-updated@example.com',
            'current_password' => 'Password1!',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.name', 'After')
        ->assertJsonPath('data.user.email', 'profile-updated@example.com');

    $this->assertDatabaseHas('users', [
        'email' => 'profile-updated@example.com',
        'name' => 'After',
    ]);
});

it('requires authentication to update a profile', function (): void {
    $this->putJson('/api/v1/profile', [
        'name' => 'After',
        'email' => 'after@example.com',
    ])->assertUnauthorized();
});

it('updates the authenticated user password', function (): void {
    User::factory()->create([
        'email' => 'password@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('password@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile/password', [
            'current_password' => 'Password1!',
            'password' => 'Password2!',
            'password_confirmation' => 'Password2!',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Password updated successfully.');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'password@example.com',
        'password' => 'Password2!',
    ])->assertOk();
});

it('signs out other sessions when the password changes', function (): void {
    User::factory()->create([
        'email' => 'sessions@example.com',
        'password' => 'Password1!',
    ]);

    $current = loginAs('sessions@example.com');
    $other = loginAs('sessions@example.com');

    $this->withToken($current['access_token'])
        ->putJson('/api/v1/profile/password', [
            'current_password' => 'Password1!',
            'password' => 'Password2!',
            'password_confirmation' => 'Password2!',
        ])
        ->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 1);

    auth()->forgetGuards();
    $this->withToken($other['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();

    auth()->forgetGuards();
    $this->withToken($current['access_token'])->getJson('/api/v1/auth/me')->assertOk();
});

it('signs out other devices after confirming the password', function (): void {
    User::factory()->create([
        'email' => 'devices@example.com',
        'password' => 'Password1!',
    ]);

    $current = loginAs('devices@example.com');
    $other = loginAs('devices@example.com');

    $this->withToken($current['access_token'])
        ->deleteJson('/api/v1/profile/sessions', ['current_password' => 'wrong'])
        ->assertUnprocessable();

    $this->assertDatabaseCount('personal_access_tokens', 2);

    $this->withToken($current['access_token'])
        ->deleteJson('/api/v1/profile/sessions', ['current_password' => 'Password1!'])
        ->assertOk()
        ->assertJsonPath('data.revoked', 1)
        ->assertJsonPath('data.signed_out_current', false);

    $this->assertDatabaseCount('personal_access_tokens', 1);

    auth()->forgetGuards();
    $this->withToken($other['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();

    auth()->forgetGuards();
    $this->withToken($current['access_token'])->getJson('/api/v1/auth/me')->assertOk();
});

it('rejects a password update when the current password is wrong', function (): void {
    User::factory()->create([
        'email' => 'password-wrong@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('password-wrong@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile/password', [
            'current_password' => 'not-the-password',
            'password' => 'Password2!',
            'password_confirmation' => 'Password2!',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});

it('deletes the authenticated account and revokes tokens', function (): void {
    $user = User::factory()->create([
        'email' => 'delete-me@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('delete-me@example.com');

    $this->withToken($login['access_token'])
        ->deleteJson('/api/v1/profile', [
            'current_password' => 'Password1!',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Account deleted successfully.');

    $this->assertDatabaseMissing('users', [
        'id' => $user->id,
    ]);
    $this->assertDatabaseCount('personal_access_tokens', 0);

    auth()->forgetGuards();

    $this->withToken($login['access_token'])
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('updates authenticated user preferences', function (): void {
    User::factory()->create([
        'email' => 'prefs@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('prefs@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile/preferences', [
            'email_notifications' => false,
            'push_notifications' => true,
            'theme' => 'dark',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.email_notifications', false)
        ->assertJsonPath('data.user.push_notifications', true)
        ->assertJsonPath('data.user.theme', 'dark');

    $this->assertDatabaseHas('users', [
        'email' => 'prefs@example.com',
        'email_notifications' => false,
        'push_notifications' => true,
        'theme' => 'dark',
    ]);
});

it('rejects an invalid theme preference', function (): void {
    User::factory()->create([
        'email' => 'prefs-invalid@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('prefs-invalid@example.com');

    $this->withToken($login['access_token'])
        ->putJson('/api/v1/profile/preferences', [
            'theme' => 'neon',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['theme']);
});

it('uploads and removes an authenticated user avatar', function (): void {
    Storage::fake('public');

    User::factory()->create([
        'email' => 'avatar@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('avatar@example.com');

    $upload = $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 800, 600),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    $avatarUrl = $upload->json('data.user.avatar');
    expect($avatarUrl)->toEndWith('.webp');

    $user = User::query()->where('email', 'avatar@example.com')->firstOrFail();
    expect($user->avatar)->toEndWith('.webp');
    Storage::disk('public')->assertExists($user->avatar);

    $this->withToken($login['access_token'])
        ->deleteJson('/api/v1/profile/avatar')
        ->assertOk()
        ->assertJsonPath('data.user.avatar', null);

    Storage::disk('public')->assertMissing($user->avatar);
    $this->assertDatabaseHas('users', [
        'email' => 'avatar@example.com',
        'avatar' => null,
    ]);
});

it('replaces an avatar and deletes the previous file', function (): void {
    Storage::fake('public');

    User::factory()->create([
        'email' => 'avatar-replace@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('avatar-replace@example.com');

    $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg', 400, 400),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    $first = User::query()->where('email', 'avatar-replace@example.com')->firstOrFail()->avatar;

    $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('second.png', 640, 480),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    $second = User::query()->where('email', 'avatar-replace@example.com')->firstOrFail()->avatar;

    expect($second)->not->toBe($first)
        ->and($second)->toEndWith('.webp');

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

it('deletes the avatar file when the account is deleted', function (): void {
    Storage::fake('public');

    $user = User::factory()->create([
        'email' => 'avatar-account@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('avatar-account@example.com');

    $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('keep.jpg', 300, 300),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    $path = $user->fresh()->avatar;
    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);

    $this->withToken($login['access_token'])
        ->deleteJson('/api/v1/profile', [
            'current_password' => 'Password1!',
        ])
        ->assertOk();

    Storage::disk('public')->assertMissing($path);
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

it('stores a sanitized svg avatar and strips active content', function (): void {
    Storage::fake('public');

    User::factory()->create([
        'email' => 'avatar-svg@example.com',
        'password' => 'Password1!',
    ]);

    $login = loginAs('avatar-svg@example.com');
    $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8">
  <circle cx="4" cy="4" r="3" onclick="alert(1)"></circle>
  <script>alert(1)</script>
</svg>
SVG;

    $upload = $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent('avatar.svg', $svg),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertOk();

    expect($upload->json('data.user.avatar'))->toEndWith('.svg');

    $user = User::query()->where('email', 'avatar-svg@example.com')->firstOrFail();
    expect($user->avatar)->toEndWith('.svg');
    Storage::disk('public')->assertExists($user->avatar);

    $stored = Storage::disk('public')->get($user->avatar);
    expect($stored)->toContain('<circle')
        ->and($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('onclick');
});

it('rejects an svg avatar that cannot be sanitized', function (): void {
    User::factory()->create([
        'email' => 'avatar-svg-bad@example.com',
        'password' => 'Password1!',
    ]);

    Storage::fake('public');

    $login = loginAs('avatar-svg-bad@example.com');

    $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent(
                'avatar.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><circle></svg>',
            ),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertStatus(HttpResponse::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('message', 'This image could not be sanitized.')
        ->assertJsonPath('data', null);
});

it('returns an unprocessable envelope when avatar encoding fails', function (): void {
    User::factory()->create([
        'email' => 'avatar-fail@example.com',
        'password' => 'Password1!',
    ]);

    $this->mock(ImageConversionService::class, function ($mock): void {
        $mock->shouldReceive('convertAndStore')
            ->once()
            ->andThrow(new RuntimeException('WebP encoding is unavailable. Enable GD WebP or install cwebp.'));
    });

    $login = loginAs('avatar-fail@example.com');

    $this->withToken($login['access_token'])
        ->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ], [
            'Accept' => 'application/json',
        ])
        ->assertStatus(HttpResponse::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('message', 'WebP encoding is unavailable. Enable GD WebP or install cwebp.')
        ->assertJsonPath('data', null);
});
