<?php

use App\Models\Laboratory;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function activeLaboratory(): Laboratory
{
    return Laboratory::query()->create([
        'name' => 'Laboratório Auth',
        'code' => 'auth-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'database_url' => 'postgresql://auth.invalid/lab',
    ]);
}

it('expõe o cookie CSRF oficial do Sanctum', function () {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('autentica usuário operacional ativo por sessão e retorna a identidade atual', function () {
    $laboratory = activeLaboratory();
    $user = User::factory()->create([
        'laboratory_id' => $laboratory->getKey(),
        'role' => 'admin',
        'status' => 'active',
        'is_super_admin' => false,
        'password' => Hash::make('senha-segura'),
    ]);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'senha-segura',
    ])->assertNoContent();

    $this->getJson('/api/auth/user')
        ->assertOk()
        ->assertJsonPath('id', $user->getKey())
        ->assertJsonPath('email', $user->email)
        ->assertJsonPath('laboratory.id', $laboratory->getKey());

    $this->postJson('/api/auth/logout')->assertNoContent();
    $this->getJson('/api/auth/user')->assertUnauthorized();
});

it('não autentica usuário operacional suspenso', function () {
    $laboratory = activeLaboratory();
    $user = User::factory()->create([
        'laboratory_id' => $laboratory->getKey(),
        'status' => 'suspended',
        'is_super_admin' => false,
        'password' => Hash::make('senha-segura'),
    ]);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'senha-segura',
    ])->assertUnprocessable();

    $this->assertGuest();
});

it('não autentica usuário ligado a laboratório suspenso', function () {
    $laboratory = activeLaboratory();
    $laboratory->forceFill(['status' => 'suspended'])->save();

    $user = User::factory()->create([
        'laboratory_id' => $laboratory->getKey(),
        'status' => 'active',
        'is_super_admin' => false,
        'password' => Hash::make('senha-segura'),
    ]);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'senha-segura',
    ])->assertUnprocessable();

    $this->assertGuest();
});
