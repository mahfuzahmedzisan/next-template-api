<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates the first admin account non-interactively with --force', function () {
    putenv('APP_INSTALL_ADMIN_NAME=Site Owner');
    putenv('APP_INSTALL_ADMIN_EMAIL=owner@example.com');
    putenv('APP_INSTALL_ADMIN_PASSWORD=super-secret-123');

    $this->artisan('app:install', ['--force' => true])
        ->assertSuccessful();

    $admin = User::query()->where('email', 'owner@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($admin->name)->toBe('Site Owner');

    putenv('APP_INSTALL_ADMIN_NAME');
    putenv('APP_INSTALL_ADMIN_EMAIL');
    putenv('APP_INSTALL_ADMIN_PASSWORD');
});

it('does not duplicate an existing admin when forced again', function () {
    User::factory()->admin()->create(['email' => 'existing-admin@example.com']);

    putenv('APP_INSTALL_ADMIN_EMAIL=existing-admin@example.com');
    putenv('APP_INSTALL_ADMIN_PASSWORD=super-secret-123');

    $this->artisan('app:install', ['--force' => true])
        ->assertSuccessful();

    expect(User::query()->where('role', UserRole::Admin)->count())->toBe(1);

    putenv('APP_INSTALL_ADMIN_EMAIL');
    putenv('APP_INSTALL_ADMIN_PASSWORD');
});
