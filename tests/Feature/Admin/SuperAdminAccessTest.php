<?php

use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redireciona visitante para o login do Super Admin', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('nega o painel a usuário autenticado sem privilégio global', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->get('/admin')
        ->assertForbidden();
});

it('permite o painel somente ao Super Admin global', function () {
    $user = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($user, 'web')
        ->get('/admin')
        ->assertOk()
        ->assertSee('Super Admin');
});

it('não permite elevar privilégio global por mass assignment', function () {
    $user = new User;
    $user->fill([
        'name' => 'Usuário comum',
        'email' => 'comum@example.com',
        'password' => 'secret',
        'is_super_admin' => true,
    ]);

    expect($user->getAttribute('is_super_admin'))->not->toBeTrue();
});
