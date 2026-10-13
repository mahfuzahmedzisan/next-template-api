<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hardens api responses, including rejected ones', function (): void {
    $ok = $this->getJson('/api/v1/posts')->assertOk();

    $this->defaultHeaders = ['Accept' => 'application/json'];
    $rejected = $this->getJson('/api/v1/posts')->assertForbidden();

    foreach ([$ok, $rejected] as $response) {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }
});

it('adds the baseline headers to the health endpoint without an api csp', function (): void {
    $this->get('/up')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeaderMissing('Content-Security-Policy');
});
