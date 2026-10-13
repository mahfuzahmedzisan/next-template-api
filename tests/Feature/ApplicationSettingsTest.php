<?php

use App\Mail\ApplicationMailManager;
use App\Models\User;
use App\Services\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('returns public settings from config when nothing is stored', function (): void {
    Config::set('app.name', 'Workspace');

    $this->getJson('/api/v1/settings/public')
        ->assertOk()
        ->assertJsonPath('data.settings.name', 'Workspace')
        ->assertJsonPath('data.settings.short_name', 'Workspace')
        ->assertJsonPath('data.settings.registration_enabled', true)
        ->assertJsonPath('data.settings.logo_url', null);
});

it('lets an admin save general settings and hides them from guests only as public fields', function (): void {
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->putJson('/api/v1/admin/settings/general', [
        'name' => 'Northwind',
        'short_name' => 'NW',
        'registration_enabled' => false,
    ])->assertOk()
        ->assertJsonPath('data.settings.general.name', 'Northwind')
        ->assertJsonPath('data.settings.general.short_name', 'NW')
        ->assertJsonPath('data.settings.general.registration_enabled', false);

    $this->getJson('/api/v1/settings/public')
        ->assertOk()
        ->assertJsonPath('data.settings.name', 'Northwind')
        ->assertJsonPath('data.settings.registration_enabled', false);
});

it('refuses application settings to a member', function (): void {
    $member = User::factory()->member()->create();

    actingAsSanctum($member)
        ->getJson('/api/v1/admin/settings')
        ->assertForbidden();
});

it('rejects public registration when the setting is off', function (): void {
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->putJson('/api/v1/admin/settings/general', [
        'name' => 'Northwind',
        'short_name' => 'NW',
        'registration_enabled' => false,
    ])->assertOk();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Public registration is turned off.');
});

it('stores smtp details without returning the password and uses them only when enabled', function (): void {
    $admin = User::factory()->admin()->create();
    $settings = app(ApplicationSettings::class);

    actingAsSanctum($admin)->putJson('/api/v1/admin/settings/smtp', [
        'enabled' => true,
        'host' => 'smtp.example.com',
        'port' => 2525,
        'encryption' => 'tls',
        'username' => 'mailer',
        'password' => 'secret-pass',
        'from_address' => 'hello@example.com',
        'from_name' => 'Northwind',
    ])->assertOk()
        ->assertJsonPath('data.settings.smtp.enabled', true)
        ->assertJsonPath('data.settings.smtp.host', 'smtp.example.com')
        ->assertJsonPath('data.settings.smtp.password_set', true)
        ->assertJsonMissingPath('data.settings.smtp.password');

    $settings->applyMailSettings();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.password'))->toBe('secret-pass')
        ->and(config('mail.from.address'))->toBe('hello@example.com');

    actingAsSanctum($admin)->putJson('/api/v1/admin/settings/smtp', [
        'enabled' => false,
        'host' => 'smtp.example.com',
        'port' => 2525,
        'encryption' => 'tls',
        'from_address' => 'hello@example.com',
    ])->assertOk();

    app(ApplicationSettings::class)->applyMailSettings();

    expect(config('mail.default'))->toBe('array');
});

it('stores a brand file on the public disk', function (): void {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();

    actingAsSanctum($admin)->post('/api/v1/admin/settings/logo', [
        'file' => UploadedFile::fake()->image('logo.png'),
    ])->assertOk();

    $url = actingAsSanctum($admin)->getJson('/api/v1/admin/settings')->json('data.settings.general.logo_url');
    expect($url)->toBeString()->toContain('/storage/settings/');

    Storage::disk('public')->assertExists(
        collect(Storage::disk('public')->allFiles('settings'))->first()
    );
});

it('strips script from an svg brand file before it is served', function (): void {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="4" onload="alert(2)"/></svg>';

    actingAsSanctum($admin)->post('/api/v1/admin/settings/logo', [
        'file' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
    ])->assertOk();

    $stored = collect(Storage::disk('public')->allFiles('settings'))->first();
    $contents = strtolower((string) Storage::disk('public')->get($stored));

    expect($stored)->toEndWith('.svg')
        ->and($contents)->toContain('circle')
        ->not->toContain('<script')
        ->not->toContain('onload');
});

it('stores an IANA timezone and treats auto as no preference', function (): void {
    $user = User::factory()->create();

    actingAsSanctum($user)->putJson('/api/v1/profile/preferences', [
        'timezone' => 'Asia/Dhaka',
    ])->assertOk()
        ->assertJsonPath('data.user.timezone', 'Asia/Dhaka');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'timezone' => 'Asia/Dhaka',
    ]);

    actingAsSanctum($user)->putJson('/api/v1/profile/preferences', [
        'timezone' => 'auto',
    ])->assertOk()
        ->assertJsonPath('data.user.timezone', null);

    actingAsSanctum($user)->putJson('/api/v1/profile/preferences', [
        'timezone' => 'Not/AZone',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['timezone']);
});

it('resolves mail through the settings aware manager', function (): void {
    expect(app('mail.manager'))->toBeInstanceOf(ApplicationMailManager::class);
});
