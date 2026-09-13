<?php

use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('expõe o cookie CSRF oficial do Sanctum', function () {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('protege a identidade da SPA com auth sanctum', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $this->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('id', $user->getKey())
        ->assertJsonPath('email', $user->email);
});
