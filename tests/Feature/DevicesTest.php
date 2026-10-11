<?php

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const DEVICE_CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

const DEVICE_SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

function deviceUser(): User
{
    return User::factory()->create([
        'email' => 'devices@example.com',
        'password' => 'Password1!',
    ]);
}

function signInFrom(string $agent = DEVICE_CHROME_WINDOWS, string $ip = '203.0.113.10', bool $remember = false): string
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => 'devices@example.com',
        'password' => 'Password1!',
        'remember' => $remember,
    ], [
        'X-Client-IP' => $ip,
        'X-Client-User-Agent' => $agent,
    ])->assertOk()->json('data.access_token');
}

function asDevice(string $token): mixed
{
    auth()->forgetGuards();

    return test()->withToken($token);
}

it('records the browser, platform and ip forwarded by the bff', function (): void {
    $user = deviceUser();
    signInFrom(DEVICE_SAFARI_IPHONE, '198.51.100.7', remember: true);

    $device = $user->devices()->sole();

    expect($device->browser)->toBe('Safari 18')
        ->and($device->platform)->toBe('iOS')
        ->and($device->device_type)->toBe('mobile')
        ->and($device->ip_address)->toBe('198.51.100.7')
        ->and($device->remember)->toBeTrue()
        ->and($device->token_id)->not->toBeNull()
        ->and($device->expires_at->greaterThan(now()->addDays(29)))->toBeTrue();
});

it('ignores a forwarded ip that is not an ip address', function (): void {
    $user = deviceUser();
    signInFrom(ip: 'not-an-ip');

    expect($user->devices()->sole()->ip_address)->toBe('127.0.0.1');
});

it('lists active devices with the current one first', function (): void {
    deviceUser();
    $first = signInFrom(DEVICE_SAFARI_IPHONE);
    signInFrom();

    asDevice($first)->getJson('/api/v1/profile/devices')
        ->assertOk()
        ->assertJsonCount(2, 'data.devices')
        ->assertJsonPath('data.devices.0.is_current', true)
        ->assertJsonPath('data.devices.0.browser', 'Safari 18')
        ->assertJsonPath('data.devices.0.status', 'active')
        ->assertJsonPath('data.devices.1.is_current', false)
        ->assertJsonMissingPath('data.devices.0.user_agent');
});

it('revokes another device and moves it to the history', function (): void {
    $user = deviceUser();
    $current = signInFrom();
    $other = signInFrom(DEVICE_SAFARI_IPHONE);
    $otherDevice = $user->devices()->where('browser', 'Safari 18')->sole();

    asDevice($current)->deleteJson("/api/v1/profile/devices/{$otherDevice->id}")->assertOk();

    asDevice($other)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $otherDevice->refresh();
    expect($otherDevice->token_id)->toBeNull()
        ->and($otherDevice->revoked_reason)->toBe('revoked')
        ->and($otherDevice->status())->toBe('revoked');

    asDevice($current)->getJson('/api/v1/profile/devices')->assertJsonCount(1, 'data.devices');
    asDevice($current)->getJson('/api/v1/profile/devices/history')
        ->assertOk()
        ->assertJsonCount(1, 'data.devices')
        ->assertJsonPath('data.devices.0.revoked_reason', 'revoked')
        ->assertJsonPath('data.meta.total', 1);
});

it('refuses to revoke the current device or a device that is already signed out', function (): void {
    $user = deviceUser();
    $current = signInFrom();
    $device = $user->devices()->sole();

    asDevice($current)->deleteJson("/api/v1/profile/devices/{$device->id}")->assertUnprocessable();

    $old = UserDevice::query()->create([
        'user_id' => $user->id,
        'revoked_at' => now(),
        'revoked_reason' => 'signed_out',
    ]);

    asDevice($current)->deleteJson("/api/v1/profile/devices/{$old->id}")->assertUnprocessable();
});

it('cannot see or touch another user\'s devices', function (): void {
    deviceUser();
    $current = signInFrom();
    $stranger = User::factory()->create();
    $theirs = UserDevice::query()->create(['user_id' => $stranger->id, 'revoked_at' => now()]);

    asDevice($current)->deleteJson("/api/v1/profile/devices/{$theirs->id}")->assertNotFound();
    asDevice($current)->deleteJson("/api/v1/profile/devices/{$theirs->id}/history")->assertNotFound();

    expect(UserDevice::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('removes inactive devices from the history but not active ones', function (): void {
    $user = deviceUser();
    $current = signInFrom();
    $active = $user->devices()->sole();
    $old = UserDevice::query()->create(['user_id' => $user->id, 'revoked_at' => now(), 'revoked_reason' => 'signed_out']);

    asDevice($current)->deleteJson("/api/v1/profile/devices/{$active->id}/history")->assertUnprocessable();
    asDevice($current)->deleteJson("/api/v1/profile/devices/{$old->id}/history")->assertOk();

    expect(UserDevice::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(UserDevice::query()->whereKey($active->id)->exists())->toBeTrue();
});

it('clears the whole history and keeps active devices', function (): void {
    $user = deviceUser();
    $current = signInFrom();
    UserDevice::query()->create(['user_id' => $user->id, 'revoked_at' => now()]);
    UserDevice::query()->create(['user_id' => $user->id, 'token_id' => null]);

    asDevice($current)->deleteJson('/api/v1/profile/devices/history')
        ->assertOk()
        ->assertJsonPath('data.removed', 2);

    expect($user->devices()->count())->toBe(1);
});

it('signs out every other device and keeps this one', function (): void {
    $user = deviceUser();
    $current = signInFrom();
    $other = signInFrom(DEVICE_SAFARI_IPHONE);

    asDevice($current)->deleteJson('/api/v1/profile/sessions', ['current_password' => 'Password1!'])
        ->assertOk()
        ->assertJsonPath('data.revoked', 1)
        ->assertJsonPath('data.signed_out_current', false);

    asDevice($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
    asDevice($current)->getJson('/api/v1/auth/me')->assertOk();

    expect($user->devices()->where('revoked_reason', 'signed_out_everywhere')->count())->toBe(1);
});

it('signs out every device including this one', function (): void {
    $user = deviceUser();
    $current = signInFrom();
    signInFrom(DEVICE_SAFARI_IPHONE);

    asDevice($current)->deleteJson('/api/v1/profile/sessions', [
        'current_password' => 'Password1!',
        'include_current' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.revoked', 1)
        ->assertJsonPath('data.signed_out_current', true);

    asDevice($current)->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect($user->tokens()->count())->toBe(0)
        ->and($user->devices()->whereNull('revoked_at')->count())->toBe(0);
});

it('marks the device as signed out on logout', function (): void {
    $user = deviceUser();
    $current = signInFrom();

    asDevice($current)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->devices()->sole()->revoked_reason)->toBe('signed_out');
});

it('marks other devices as revoked by a password change', function (): void {
    $user = deviceUser();
    $current = signInFrom();
    signInFrom(DEVICE_SAFARI_IPHONE);

    asDevice($current)->putJson('/api/v1/profile/password', [
        'current_password' => 'Password1!',
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ])->assertOk();

    expect($user->devices()->where('browser', 'Safari 18')->sole()->revoked_reason)->toBe('password_changed')
        ->and($user->devices()->where('browser', 'Chrome 141')->sole()->revoked_at)->toBeNull();
});

it('prunes devices revoked or expired more than 30 days ago', function (): void {
    $user = deviceUser();
    signInFrom();

    $oldRevoked = UserDevice::query()->create(['user_id' => $user->id, 'revoked_at' => now()->subDays(31)]);
    $oldExpired = UserDevice::query()->create(['user_id' => $user->id, 'expires_at' => now()->subDays(31)]);
    $recent = UserDevice::query()->create(['user_id' => $user->id, 'revoked_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [UserDevice::class]])->assertSuccessful();

    expect(UserDevice::query()->whereKey([$oldRevoked->id, $oldExpired->id])->count())->toBe(0)
        ->and(UserDevice::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and($user->devices()->whereNotNull('token_id')->count())->toBe(1);
});
