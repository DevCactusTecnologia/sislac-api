<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');
});

it('nega rota clínica sem bearer sem expor detalhe interno', function () {
    $response = $this->getJson('/api/pacientes');

    $response->assertUnauthorized()
        ->assertJson([
            'message' => 'Não autenticado.',
        ]);

    expect($response->getContent())
        ->not->toContain('trace')
        ->not->toContain('vendor/')
        ->not->toContain('APP_KEY')
        ->not->toContain('DB_PASSWORD');
});

it('nega bearer inválido sem expor detalhe do Supabase ou token', function () {
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'message' => 'invalid token',
        ], 401),
    ]);

    $response = $this->withToken('sensitive-invalid-token')
        ->getJson('/api/pacientes');

    $response->assertUnauthorized()
        ->assertJson([
            'message' => 'Não autenticado.',
        ]);

    expect($response->getContent())
        ->not->toContain('invalid token')
        ->not->toContain('sensitive-invalid-token')
        ->not->toContain('trace')
        ->not->toContain('vendor/');
});
