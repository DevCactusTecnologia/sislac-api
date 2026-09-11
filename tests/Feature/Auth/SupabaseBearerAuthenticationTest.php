<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.publishable_key', 'test-publishable-key');

    Route::middleware('supabase.auth')
        ->get('/_test/supabase-auth-principal', function (Request $request) {
            $user = $request->user();

            return response()->json([
                'id' => $user?->getAuthIdentifier(),
                'email' => is_object($user) && method_exists($user, 'getAttribute')
                    ? $user->getAttribute('email')
                    : null,
            ]);
        });
});

it('aceita Bearer Supabase válido sem exigir usuário correlacionado no Laravel', function () {
    $userId = '11111111-1111-4111-8111-111111111111';

    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'id' => $userId,
            'email' => 'analista@example.test',
        ], 200),
    ]);

    $this->withToken('valid-supabase-token')
        ->getJson('/_test/supabase-auth-principal')
        ->assertOk()
        ->assertJson([
            'id' => $userId,
            'email' => 'analista@example.test',
        ]);
});

it('nega requisição clínica sem Bearer', function () {
    $this->getJson('/_test/supabase-auth-principal')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Não autenticado.']);
});

it('nega Bearer rejeitado pelo Supabase sem expor resposta interna', function () {
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([
            'message' => 'invalid jwt',
        ], 401),
    ]);

    $response = $this->withToken('sensitive-invalid-token')
        ->getJson('/_test/supabase-auth-principal');

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Não autenticado.']);

    expect($response->getContent())
        ->not->toContain('invalid jwt')
        ->not->toContain('sensitive-invalid-token');
});

it('falha fechado quando o Supabase Auth está indisponível', function () {
    Http::fake([
        'https://example.supabase.co/auth/v1/user' => Http::response([], 503),
    ]);

    $this->withToken('valid-but-upstream-unavailable')
        ->getJson('/_test/supabase-auth-principal')
        ->assertStatus(503)
        ->assertJson(['message' => 'Serviço de autenticação indisponível.']);
});
