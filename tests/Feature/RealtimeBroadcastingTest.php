<?php

use App\Events\RealtimePrivateTestEvent;
use App\Events\ReverbPingEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key-value',
        'broadcasting.connections.reverb.secret' => 'test-secret-value',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options' => [
            'host' => 'localhost',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
        ],
    ]);

    app()->forgetInstance('Illuminate\Broadcasting\BroadcastManager');
    app()->forgetInstance('Illuminate\Contracts\Broadcasting\Factory');
    Broadcast::clearResolvedInstance('Illuminate\Contracts\Broadcasting\Factory');

    require base_path('routes/channels.php');
});

function realtimeToken(): string
{
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ]);

    return test()->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'Password1!',
    ])->json('data.access_token');
}

it('authorizes the owner of a private user channel', function (): void {
    $token = realtimeToken();
    $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

    $this->withToken($token)
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$user->id,
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

it('rejects a private user channel for someone else', function (): void {
    $token = realtimeToken();
    $other = User::factory()->create();

    $this->withToken($token)
        ->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$other->id,
        ])
        ->assertForbidden();
});

it('rejects broadcast auth without a bearer token', function (): void {
    $this->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-App.Models.User.1',
    ])->assertUnauthorized();
});

it('dispatches a public reverb ping', function (): void {
    Event::fake([ReverbPingEvent::class]);
    $token = realtimeToken();

    $this->withToken($token)
        ->getJson('/api/v1/realtime/ping?message=hello')
        ->assertOk()
        ->assertJsonPath('data.channel', 'reverb-ping')
        ->assertJsonPath('data.event', 'ping')
        ->assertJsonPath('data.payload.message', 'hello');

    Event::assertDispatched(ReverbPingEvent::class);
});

it('dispatches a private realtime test to the current user', function (): void {
    Event::fake([RealtimePrivateTestEvent::class]);
    $token = realtimeToken();
    $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

    $this->withToken($token)
        ->postJson('/api/v1/realtime/test-notification')
        ->assertOk()
        ->assertJsonPath('data.channel', 'App.Models.User.'.$user->id)
        ->assertJsonPath('data.event', 'realtime.test');

    Event::assertDispatched(RealtimePrivateTestEvent::class);
});

it('reports broadcasting config without secrets', function (): void {
    $token = realtimeToken();

    $response = $this->withToken($token)
        ->getJson('/api/v1/realtime/health')
        ->assertOk()
        ->assertJsonPath('data.broadcast_connection', 'reverb')
        ->assertJsonPath('data.host', 'localhost')
        ->assertJsonPath('data.app_key_preview', 'test-key...');

    expect($response->getContent())->not->toContain('test-secret-value');
});

it('keeps the realtime diagnostics admin only', function (): void {
    $member = User::factory()->create();

    actingAsSanctum($member)->getJson('/api/v1/realtime/ping?message=hello')->assertForbidden();
    actingAsSanctum($member)->postJson('/api/v1/realtime/test-notification')->assertForbidden();
});
