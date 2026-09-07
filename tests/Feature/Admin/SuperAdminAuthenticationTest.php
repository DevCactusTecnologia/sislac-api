<?php

use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('autentica Super Admin com a conta central existente', function () {
    $user = User::factory()->create(['is_super_admin' => true]);

    $this->post('/admin/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user, 'web');
});

it('não mantém usuário comum autenticado pelo login administrativo', function () {
    $user = User::factory()->create(['is_super_admin' => false]);

    $this->post('/admin/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertForbidden();

    $this->assertGuest('web');
});

it('recusa credenciais inválidas sem autenticar', function () {
    $user = User::factory()->create(['is_super_admin' => true]);

    $this->from('/admin/login')->post('/admin/login', [
        'email' => $user->email,
        'password' => 'senha-incorreta',
    ])->assertRedirect('/admin/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

it('encerra a sessão do Super Admin', function () {
    $user = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($user, 'web')
        ->post('/admin/logout')
        ->assertRedirect('/admin/login');

    $this->assertGuest('web');
});

it('limita tentativas repetidas no login administrativo', function () {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->post('/admin/login', [
            'email' => 'inexistente@example.com',
            'password' => 'incorreta',
        ])->assertStatus(302);
    }

    $this->post('/admin/login', [
        'email' => 'inexistente@example.com',
        'password' => 'incorreta',
    ])->assertTooManyRequests();
});
