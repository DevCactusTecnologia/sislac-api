<?php

use App\Platform\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('cria o primeiro Super Admin com senha oculta e confirmação', function () {
    $this->artisan('admin:super-user', ['email' => 'owner@example.com'])
        ->expectsQuestion('Nome', 'Administrador SISLAC')
        ->expectsQuestion('Senha', 'senha-segura-de-bootstrap')
        ->expectsQuestion('Confirme a senha', 'senha-segura-de-bootstrap')
        ->assertSuccessful();

    $user = User::query()->where('email', 'owner@example.com')->firstOrFail();

    expect($user->name)->toBe('Administrador SISLAC')
        ->and($user->is_super_admin)->toBeTrue()
        ->and(Hash::check('senha-segura-de-bootstrap', $user->password))->toBeTrue();
});

it('promove usuário existente sem alterar sua senha', function () {
    $user = User::factory()->create([
        'email' => 'existing@example.com',
        'password' => 'senha-original',
        'is_super_admin' => false,
    ]);

    $this->artisan('admin:super-user', ['email' => $user->email])
        ->assertSuccessful();

    $user->refresh();

    expect($user->is_super_admin)->toBeTrue()
        ->and(Hash::check('senha-original', $user->password))->toBeTrue();
});

it('não cria Super Admin quando a confirmação da senha diverge', function () {
    $this->artisan('admin:super-user', ['email' => 'invalid@example.com'])
        ->expectsQuestion('Nome', 'Administrador SISLAC')
        ->expectsQuestion('Senha', 'senha-um')
        ->expectsQuestion('Confirme a senha', 'senha-dois')
        ->assertFailed();

    expect(User::query()->where('email', 'invalid@example.com')->exists())->toBeFalse();
});

it('DatabaseSeeder não cria usuário de scaffold', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->exists())->toBeFalse();
});
