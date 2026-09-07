<?php

use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'https://sislac.com.br');
});

it('autentica a SPA e expõe somente os campos públicos do usuário', function () {
    $user = User::factory()->create([
        'name' => 'Ana Laboratório',
        'email' => 'ana@example.test',
        'password' => 'senha-segura',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => '  ANA@EXAMPLE.TEST  ',
        'password' => 'senha-segura',
    ]);

    $response->assertOk();

    expect($response->json('user'))->toBe([
        'id' => $user->id,
        'name' => 'Ana Laboratório',
        'email' => 'ana@example.test',
    ]);

    $this->assertAuthenticatedAs($user);
});

it('regenera a sessão depois de autenticar', function () {
    $user = User::factory()->create([
        'email' => 'sessao@example.test',
        'password' => 'senha-segura',
    ]);

    $this->withSession(['probe' => 'preserve']);
    $sessionBeforeLogin = session()->getId();

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'senha-segura',
    ])->assertOk();

    expect(session()->getId())
        ->not->toBe($sessionBeforeLogin)
        ->and(session('probe'))->toBe('preserve');
});

it('não revela se a conta existe quando as credenciais são inválidas', function () {
    User::factory()->create([
        'email' => 'existente@example.test',
        'password' => 'senha-correta',
    ]);

    $wrongPassword = $this->postJson('/api/auth/login', [
        'email' => 'existente@example.test',
        'password' => 'senha-incorreta',
    ]);

    $unknownUser = $this->postJson('/api/auth/login', [
        'email' => 'inexistente@example.test',
        'password' => 'senha-incorreta',
    ]);

    $wrongPassword->assertUnprocessable();
    $unknownUser->assertUnprocessable();

    expect($wrongPassword->json('errors.email'))
        ->toBe(['Credenciais inválidas.'])
        ->and($unknownUser->json('errors.email'))
        ->toBe(['Credenciais inválidas.']);
});

it('invalida a sessão no logout', function () {
    $user = User::factory()->create([
        'email' => 'logout@example.test',
        'password' => 'senha-segura',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'senha-segura',
    ])->assertOk();

    $this->postJson('/api/auth/logout')->assertNoContent();

    $this->assertGuest();
    $this->getJson('/api/auth/session')->assertUnauthorized();
});

it('nega consulta da sessão sem autenticação', function () {
    $this->getJson('/api/auth/session')->assertUnauthorized();
});

it('limita tentativas repetidas de login por email e ip', function () {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/auth/login', [
            'email' => 'bruteforce@example.test',
            'password' => 'senha-incorreta',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/auth/login', [
        'email' => 'bruteforce@example.test',
        'password' => 'senha-incorreta',
    ])->assertTooManyRequests();
});
