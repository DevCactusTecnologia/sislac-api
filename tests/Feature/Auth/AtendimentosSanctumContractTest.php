<?php

use App\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');
});

it('exige laboratório após autenticar a sessão Laravel', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->getJson('/api/atendimentos')
        ->assertForbidden();
});

it('rejeita Bearer Supabase nas rotas clínicas migradas', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $user->id,
            'email' => $user->email,
        ], 200),
    ]);

    $this->withToken('valid-atendimentos-token')
        ->getJson('/api/atendimentos')
        ->assertUnauthorized();

    Http::assertNothingSent();
});
