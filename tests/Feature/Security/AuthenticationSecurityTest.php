<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'https://sislac.com.br');
});

it('responde payload inválido sem stack trace nem detalhe interno', function () {
    $response = $this->postJson('/api/auth/login', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);

    $payload = $response->getContent();

    expect($payload)
        ->not->toContain('trace')
        ->not->toContain('vendor/')
        ->not->toContain('APP_KEY')
        ->not->toContain('DB_PASSWORD');
});

it('não autentica rota protegida com sessão inexistente', function () {
    $this->getJson('/api/auth/session')->assertUnauthorized();
});
