<?php

use App\Models\Laboratory;
use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function activeLaboratoryForAuth(): Laboratory
{
    return Laboratory::query()->create([
        'name' => 'Laboratório Auth',
        'code' => 'auth-'.Str::lower(Str::random(10)),
        'status' => 'active',
        'database_url' => null,
    ]);
}

it('expõe o cookie csrf oficial do sanctum', function () {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('autentica usuário operacional por sessão laravel e regenera a sessão', function () {
    $laboratory = activeLaboratoryForAuth();
    $user = User::factory()->create([
        'email' => 'operacional@sislac.test',
        'laboratory_id' => $laboratory->getKey(),
        'status' => 'active',
        'is_super_admin' => false,
    ]);

    $this->withSession(['probe' => true]);
    $oldSessionId = session()->getId();

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertNoContent();

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($oldSessionId);
});

it('rejeita credenciais inválidas sem autenticar', function () {
    $laboratory = activeLaboratoryForAuth();
    $user = User::factory()->create([
        'email' => 'invalido@sislac.test',
        'laboratory_id' => $laboratory->getKey(),
        'status' => 'active',
        'is_super_admin' => false,
    ]);

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'senha-incorreta',
    ])->assertUnprocessable();

    $this->assertGuest();
});

it('não permite super admin no login clínico', function () {
    $user = User::factory()->create([
        'email' => 'superadmin@sislac.test',
        'is_super_admin' => true,
        'laboratory_id' => null,
    ]);

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertUnprocessable();

    $this->assertGuest();
});

it('resolve usuário e laboratório pela sessão sanctum sem bearer supabase', function () {
    $laboratory = activeLaboratoryForAuth();
    $user = User::factory()->create([
        'email' => 'sessao@sislac.test',
        'laboratory_id' => $laboratory->getKey(),
        'status' => 'active',
        'is_super_admin' => false,
    ]);

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertNoContent();

    $this->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('id', $user->getKey())
        ->assertJsonPath('laboratory.id', $laboratory->getKey())
        ->assertJsonPath('laboratory.name', $laboratory->name);
});

it('protege a rota de usuário com auth sanctum', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});

it('logout invalida autenticação e renova a sessão', function () {
    $laboratory = activeLaboratoryForAuth();
    $user = User::factory()->create([
        'email' => 'logout@sislac.test',
        'laboratory_id' => $laboratory->getKey(),
        'status' => 'active',
        'is_super_admin' => false,
    ]);

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertNoContent();

    $sessionId = session()->getId();
    $csrfToken = session()->token();

    $this->postJson('/logout')->assertNoContent();

    $this->assertGuest('web');
    expect(session()->getId())->not->toBe($sessionId)
        ->and(session()->token())->not->toBe($csrfToken);

    // Uma nova requisição não reutiliza a identidade em memória do guard anterior.
    Auth::forgetGuards();
    $this->getJson('/api/user')->assertUnauthorized();
});
