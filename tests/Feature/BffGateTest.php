<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects api requests that do not present the bff secret', function (): void {
    $this->defaultHeaders = ['Accept' => 'application/json'];

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->assertForbidden()
        ->assertJsonPath('message', 'This API only accepts requests from the trusted application.');
});

it('rejects a trusted secret from an origin that is not allowlisted', function (): void {
    $this->withHeaders([
        'X-Frontend-Origin' => 'https://not-allowlisted.example.com',
    ])->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->assertForbidden()
        ->assertJsonPath('message', 'This API only accepts requests from the trusted application.');
});

it('accepts login from an allowlisted origin with the bff secret', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->assertOk()
        ->assertJsonPath('data.user.email', 'admin@example.com')
        ->assertJsonStructure([
            'data' => ['access_token', 'session_ends_at'],
        ]);
});

it('keeps the health endpoint public', function (): void {
    $this->defaultHeaders = ['Accept' => 'application/json'];

    $this->get('/up')->assertOk();
});

it('exposes no token endpoint outside the bff gate', function (): void {
    $this->defaultHeaders = ['Accept' => 'application/json'];

    $this->post('/oauth/token', ['grant_type' => 'password'])->assertNotFound();
    $this->get('/sanctum/csrf-cookie')->assertNotFound();
});
